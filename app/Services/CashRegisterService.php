<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\CashSessionPoster;
use App\Services\Accounting\TransactionDraft;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashRegisterService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly CashSessionPoster $cashSessionPoster,
        private readonly DatabaseManager $db,
    ) {}

    public function balanceOf(FinancialAccount $account, ?Carbon $asOf = null): Money
    {
        return $this->accounting->balanceOf(
            $account->ledger_account_id,
            $asOf?->format('Y-m-d'),
            $account->branch_id,
        );
    }

    public function balanceAll(?int $branchId = null): Collection
    {
        $query = FinancialAccount::query()->where('is_active', true);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->get()->map(fn (FinancialAccount $account): array => [
            'account' => $account,
            'balance' => $this->balanceOf($account),
        ]);
    }

    public function openSession(FinancialAccount $account, string $float, User $by): CashSession
    {
        $existing = CashSession::query()
            ->where('financial_account_id', $account->id)
            ->where('status', CashSessionStatus::Open)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException('An open session already exists for this account.');
        }

        return $this->db->transaction(function () use ($account, $float, $by): CashSession {
            $session = CashSession::create([
                'branch_id' => $account->branch_id,
                'financial_account_id' => $account->id,
                'opened_by_id' => $by->id,
                'opened_at' => now(),
                'opening_float' => $float,
                'status' => CashSessionStatus::Open,
            ]);

            if ((float) $float > 0) {
                $draft = $this->cashSessionPoster->postOpeningFloat($session, $by->id);
                $this->accounting->post($draft);
            }

            return $session;
        });
    }

    public function closeSession(CashSession $session, string $counted, User $by): CashSession
    {
        if ($session->status !== CashSessionStatus::Open) {
            throw new RuntimeException('Session is not open.');
        }

        return $this->db->transaction(function () use ($session, $counted, $by): CashSession {
            $expected = $this->calculateExpected($session);
            $countedDecimal = (float) $counted;
            $expectedDecimal = (float) $expected;
            $variance = $countedDecimal - $expectedDecimal;

            $session->closed_by_id = $by->id;
            $session->closed_at = now();
            $session->counted_amount = $counted;
            $session->status = abs($variance) < 0.01
                ? CashSessionStatus::Closed
                : CashSessionStatus::Disputed;
            $session->save();

            if (abs($variance) >= 0.01) {
                $varianceAbs = number_format(abs($variance), 2, '.', '');
                if ($variance > 0) {
                    $draft = $this->cashSessionPoster->postCashOver($session, $varianceAbs, $by->id);
                } else {
                    $draft = $this->cashSessionPoster->postCashShort($session, $varianceAbs, $by->id);
                }
                $this->accounting->post($draft);
            }

            return $session->fresh();
        });
    }

    public function calculateExpected(CashSession $session): string
    {
        $accountId = $session->financialAccount->ledger_account_id;

        $debits = Transaction::query()
            ->where('cash_session_id', $session->id)
            ->where('debit_account_id', $accountId)
            ->sum('amount');

        $credits = Transaction::query()
            ->where('cash_session_id', $session->id)
            ->where('credit_account_id', $accountId)
            ->sum('amount');

        // The opening float is already captured as a debit to this account,
        // so the expected cash is simply debits minus credits.
        return number_format($debits - $credits, 2, '.', '');
    }

    public function transfer(
        FinancialAccount $from,
        FinancialAccount $to,
        string $amount,
        User $by,
    ): \App\Models\Transaction {
        $draft = new TransactionDraft(
            debitAccountId: $to->ledger_account_id,
            creditAccountId: $from->ledger_account_id,
            amount: $amount,
            type: \App\Enums\TransactionType::CashTransfer,
            occurredOn: new \DateTimeImmutable(),
            description: sprintf('Transfer from %s to %s', $from->name, $to->name),
            branchId: $from->branch_id ?? $to->branch_id,
            createdById: $by->id,
            meta: ['from_account_id' => $from->id, 'to_account_id' => $to->id],
        );

        return $this->accounting->post($draft);
    }

    public function entries(FinancialAccount $account, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = Transaction::query()
            ->where(function ($q) use ($account) {
                $q->where('debit_account_id', $account->ledger_account_id)
                    ->orWhere('credit_account_id', $account->ledger_account_id);
            });

        if ($from !== null) {
            $query->where('occurred_on', '>=', $from->format('Y-m-d'));
        }
        if ($to !== null) {
            $query->where('occurred_on', '<=', $to->format('Y-m-d'));
        }

        return $query->orderBy('occurred_on')->orderBy('id')->get();
    }
}
