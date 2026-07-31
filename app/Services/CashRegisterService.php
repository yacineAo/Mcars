<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Enums\NormalBalance;
use App\Enums\TransactionType;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
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

    /**
     * Return current balances for many accounts in a single query.
     *
     * @param Collection<int, FinancialAccount> $accounts
     * @return array<int, string> keyed by FinancialAccount id
     */
    public function balancesBatch(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        /** @var array<int, int> $ledgerIds */
        $ledgerIds = $accounts->pluck('ledger_account_id')->unique()->filter()->toArray();

        if ($ledgerIds === []) {
            return $accounts->mapWithKeys(fn (FinancialAccount $account): array => [(int) $account->id => '0.00'])->all();
        }

        /** @var Collection<int, string> $debits */
        $debits = Transaction::query()
            ->whereIn('debit_account_id', $ledgerIds)
            ->groupBy('debit_account_id')
            ->selectRaw('debit_account_id AS ledger_id, SUM(amount) AS total')
            ->pluck('total', 'ledger_id');

        /** @var Collection<int, string> $credits */
        $credits = Transaction::query()
            ->whereIn('credit_account_id', $ledgerIds)
            ->groupBy('credit_account_id')
            ->selectRaw('credit_account_id AS ledger_id, SUM(amount) AS total')
            ->pluck('total', 'ledger_id');

        /** @var Collection<int, NormalBalance> $normalBalances */
        $normalBalances = ChartOfAccount::whereIn('id', $ledgerIds)
            ->pluck('normal_balance', 'id');

        $result = [];
        foreach ($accounts as $account) {
            $ledgerId = $account->ledger_account_id;
            $drTotal = (float) ($debits[$ledgerId] ?? 0);
            $crTotal = (float) ($credits[$ledgerId] ?? 0);
            $nb = 'debit';
            if (isset($normalBalances[$ledgerId])) {
                $nb = $normalBalances[$ledgerId]->value;
            }
            $balance = $nb === 'debit' ? $drTotal - $crTotal : $crTotal - $drTotal;
            $result[(int) $account->id] = number_format(max(0, $balance), 2, '.', '');
        }

        return $result;
    }

    /**
     * Total cash held across every cash-equivalent ledger account (REQ-09).
     *
     * Derived from the chart of accounts rather than from `financial_accounts`, so
     * cash posted to an account that has no operational till record still counts.
     * A cash-to-cash transfer contributes to both sides of the sum and nets to zero.
     */
    public function cashOnHand(?int $branchId = null, ?Carbon $asOf = null): Money
    {
        $row = Transaction::query()
            ->join('chart_of_accounts as a', function ($join) {
                $join->on('a.id', '=', 'transactions.debit_account_id')
                    ->orOn('a.id', '=', 'transactions.credit_account_id');
            })
            ->where('a.is_cash_equivalent', true)
            ->when($asOf !== null, fn ($q) => $q->where('transactions.occurred_on', '<=', $asOf->format('Y-m-d')))
            ->when($branchId !== null, fn ($q) => $q->where('transactions.branch_id', $branchId))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN a.id = transactions.debit_account_id THEN transactions.amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN a.id = transactions.credit_account_id THEN transactions.amount ELSE 0 END), 0) AS balance
            ')
            ->first();

        return Money::of((string) ($row->balance ?? '0'));
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

    public function openSession(FinancialAccount $account, string|int|float $float, User $by): CashSession
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

    public function closeSession(CashSession $session, string|int|float $counted, User $by, ?string $notes = null): CashSession
    {
        if ($session->status !== CashSessionStatus::Open) {
            throw new RuntimeException('Session is not open.');
        }

        return $this->db->transaction(function () use ($session, $counted, $by, $notes): CashSession {
            $expected = $this->calculateExpected($session);
            $variance = Money::of((string) $counted)->minus(Money::of($expected));
            $isExact = $variance->isZero();

            $session->closed_by_id = $by->id;
            $session->closed_at = now();
            $session->counted_amount = $counted;

            if ($notes !== null && trim((string) $notes) !== '') {
                $existing = trim((string) $session->notes);
                $session->notes = $existing === ''
                    ? trim((string) $notes)
                    : $existing."\n".trim((string) $notes);
            }

            $session->status = $isExact
                ? CashSessionStatus::Closed
                : CashSessionStatus::Disputed;
            $session->save();

            if (! $isExact) {
                $varianceAbs = $variance->absolute()->toDecimal();
                if ($variance->isPositive()) {
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
        $account = $session->financialAccount;
        assert($account !== null);
        $accountId = $account->ledger_account_id;

        // The session's own close-time variance postings (E68/E69) are excluded:
        // they were written to make the ledger match the counted amount, so
        // including them would always show "expected == counted, variance 0"
        // for a closed session and hide the very figure this exists to report.
        $debits = Transaction::query()
            ->where('cash_session_id', $session->id)
            ->where('debit_account_id', $accountId)
            ->whereNotIn('type', [TransactionType::CashOver, TransactionType::CashShort])
            ->sum('amount');

        $credits = Transaction::query()
            ->where('cash_session_id', $session->id)
            ->where('credit_account_id', $accountId)
            ->whereNotIn('type', [TransactionType::CashOver, TransactionType::CashShort])
            ->sum('amount');

        // The opening float is already captured as a debit to this account,
        // so the expected cash is simply debits minus credits.
        return Money::of((string) $debits)
            ->minus(Money::of((string) $credits))
            ->toDecimal();
    }

    /**
     * Expected and variance for one session, in one place.
     *
     * This is the same arithmetic `closeSession()` performs — the figure the
     * business cares about — so screens show exactly what the close posted.
     * Variance is null while the session is still open (nothing counted yet).
     *
     * @return array{expected: string, variance: ?string}
     */
    public function reconciliation(CashSession $session): array
    {
        $expected = $this->calculateExpected($session);

        $counted = $session->counted_amount;
        $variance = $counted === null
            ? null
            : Money::of($counted)->minus(Money::of($expected))->toDecimal();

        return ['expected' => $expected, 'variance' => $variance];
    }

    /**
     * Expected and variance for many sessions in one pass.
     *
     * The index table renders one row at a time, so calling `reconciliation()`
     * per row would cost two aggregate queries per visible row. This runs the
     * same arithmetic (same joins, same exclusions) as a single grouped query
     * for the whole collection.
     *
     * @param Collection<int, CashSession> $sessions
     * @return array<int, array{expected: string, variance: ?string}> keyed by session id
     */
    public function reconciliations(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        /** @var array<int, int> $ids */
        $ids = $sessions->pluck('id')->map(fn (int $id): int => $id)->all();

        $debits = $this->sessionTotals($ids, 'debit_account_id');
        $credits = $this->sessionTotals($ids, 'credit_account_id');

        $result = [];
        foreach ($sessions as $session) {
            $id = (int) $session->id;

            $expected = Money::of($debits[$id] ?? '0')
                ->minus(Money::of($credits[$id] ?? '0'))
                ->toDecimal();

            $counted = $session->counted_amount;

            $result[$id] = [
                'expected' => $expected,
                'variance' => $counted === null
                    ? null
                    : Money::of($counted)->minus(Money::of($expected))->toDecimal(),
            ];
        }

        return $result;
    }

    /**
     * Total postings of one side for many sessions, restricted to each
     * session's own ledger account. Mirrors `calculateExpected()`: the joins
     * are exactly the per-session query's, so the two never disagree.
     *
     * @param array<int, int> $ids
     * @return array<int, string> keyed by session id
     */
    private function sessionTotals(array $ids, string $column): array
    {
        $rows = Transaction::query()
            ->join('cash_sessions', 'cash_sessions.id', '=', 'transactions.cash_session_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'cash_sessions.financial_account_id')
            ->whereIn('transactions.cash_session_id', $ids)
            ->whereColumn('transactions.'.$column, 'financial_accounts.ledger_account_id')
            ->whereNotIn('transactions.type', [TransactionType::CashOver, TransactionType::CashShort])
            ->groupBy('transactions.cash_session_id')
            ->selectRaw('transactions.cash_session_id AS session_id, SUM(transactions.amount) AS total')
            ->pluck('total', 'session_id');

        $totals = [];
        foreach ($rows as $sessionId => $total) {
            $totals[(int) $sessionId] = (string) $total;
        }

        return $totals;
    }

    public function transfer(
        FinancialAccount $from,
        FinancialAccount $to,
        string $amount,
        User $by,
    ): Transaction {
        $draft = new TransactionDraft(
            debitAccountId: $to->ledger_account_id,
            creditAccountId: $from->ledger_account_id,
            amount: $amount,
            type: TransactionType::CashTransfer,
            occurredOn: new \DateTimeImmutable,
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
