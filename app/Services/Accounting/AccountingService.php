<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Events\TransactionPosted;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\Sequences\SequenceGenerator;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class AccountingService
{
    private const string REFERENCE_KEY = 'transaction';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SequenceGenerator $sequences,
    ) {}

    public function post(TransactionDraft $draft): Transaction
    {
        $rows = $this->postMany($draft);

        return $rows->first();
    }

    public function postMany(TransactionDraft ...$drafts): Collection
    {
        return $this->db->transaction(function () use ($drafts): Collection {
            $groupUuid = (string) Str::uuid();

            return collect($drafts)->map(function (TransactionDraft $draft) use ($groupUuid): Transaction {
                $this->validateDraft($draft);

                // The counter is scoped per (key, branch, year), so every branch starts
                // at 1 — the branch code is what keeps the globally-unique reference
                // unique. Omitting it makes branch B's first posting collide with
                // branch A's TRX-2026-000001.
                $reference = $this->sequences->next(
                    self::REFERENCE_KEY,
                    $draft->branchId,
                    $this->branchCode($draft->branchId),
                );

                $meta = $draft->meta ?? [];
                $meta['group_uuid'] = $groupUuid;

                $transaction = new Transaction([
                    'uuid' => (string) Str::uuid(),
                    'reference' => $reference,
                    'branch_id' => $draft->branchId,
                    'occurred_on' => $draft->occurredOn->format('Y-m-d'),
                    'posted_at' => CarbonImmutable::now(),
                    'debit_account_id' => $draft->debitAccountId,
                    'credit_account_id' => $draft->creditAccountId,
                    'amount' => $draft->amount,
                    'currency' => $draft->currency,
                    'type' => $draft->type,
                    'payment_method' => $draft->paymentMethod,
                    'description' => $draft->description,
                    'car_id' => $draft->carId,
                    'booking_id' => $draft->bookingId,
                    'contract_id' => $draft->contractId,
                    'customer_id' => $draft->customerId,
                    'car_owner_id' => $draft->carOwnerId,
                    'employee_id' => $draft->employeeId,
                    'expense_category_id' => $draft->expenseCategoryId,
                    'source_type' => $draft->sourceType,
                    'source_id' => $draft->sourceId,
                    'created_by_id' => $draft->createdById,
                    'cash_session_id' => $draft->cashSessionId,
                    'reverses_transaction_id' => $draft->reversesTransactionId,
                    'is_reversal' => $draft->isReversal,
                    'meta' => $meta,
                ]);

                $transaction->save();

                // After commit only: firing inside the transaction lets a concurrent
                // reader re-prime the report cache from pre-commit state, and a
                // rollback would flush caches for rows that never existed.
                $this->db->connection()->afterCommit(
                    fn () => TransactionPosted::dispatch($transaction),
                );

                return $transaction;
            });
        });
    }

    public function reverse(Transaction $transaction, string $reason, ?User $by = null): Transaction
    {
        if ($transaction->is_reversal) {
            throw new RuntimeException('Cannot reverse a reversal transaction.');
        }

        // Check if already reversed
        $existingReversal = Transaction::where('reverses_transaction_id', $transaction->id)
            ->where('is_reversal', true)
            ->exists();

        if ($existingReversal) {
            throw new RuntimeException('Transaction has already been reversed.');
        }

        $draft = new TransactionDraft(
            debitAccountId: $transaction->credit_account_id,
            creditAccountId: $transaction->debit_account_id,
            amount: $transaction->amount,
            type: TransactionType::Reversal,
            occurredOn: new DateTimeImmutable,
            description: sprintf('Reversal of %s: %s', $transaction->reference, $reason),
            branchId: $transaction->branch_id,
            currency: $transaction->currency,
            carId: $transaction->car_id,
            bookingId: $transaction->booking_id,
            contractId: $transaction->contract_id,
            customerId: $transaction->customer_id,
            carOwnerId: $transaction->car_owner_id,
            employeeId: $transaction->employee_id,
            expenseCategoryId: $transaction->expense_category_id,
            sourceType: $transaction->source_type,
            sourceId: $transaction->source_id,
            createdById: $by?->id,
            cashSessionId: $transaction->cash_session_id,
            isReversal: true,
            reversesTransactionId: $transaction->id,
            meta: ['reversal_reason' => $reason, 'reversed_by_user_id' => $by?->id],
        );

        return $this->post($draft);
    }

    public function balanceOf(int $accountId, ?string $asOf = null, ?int $branchId = null): Money
    {
        $query = Transaction::query()
            ->where(function ($q) use ($accountId) {
                $q->where('debit_account_id', $accountId)
                    ->orWhere('credit_account_id', $accountId);
            });

        if ($asOf !== null) {
            $query->where('occurred_on', '<=', $asOf);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $debits = (clone $query)->where('debit_account_id', $accountId)->sum('amount');
        $credits = (clone $query)->where('credit_account_id', $accountId)->sum('amount');

        $account = ChartOfAccount::find($accountId);
        if ($account === null) {
            return Money::zero();
        }

        $normalBalance = $account->normal_balance->value;

        $balance = $normalBalance === 'debit'
            ? $debits - $credits
            : $credits - $debits;

        return Money::of(max(0, $balance));
    }

    /**
     * Branch codes are immutable identifiers, so memoising them for the life of the
     * request avoids a lookup on every posting in a batch.
     *
     * @var array<int, string|null>
     */
    private array $branchCodes = [];

    private function branchCode(?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return $this->branchCodes[$branchId] ??= Branch::query()->whereKey($branchId)->value('code');
    }

    private function validateDraft(TransactionDraft $draft): void
    {
        if ((float) $draft->amount <= 0) {
            throw new RuntimeException('Transaction amount must be positive.');
        }

        if ($draft->debitAccountId === $draft->creditAccountId) {
            throw new RuntimeException('Debit and credit accounts must be different.');
        }

        $debitAccount = ChartOfAccount::find($draft->debitAccountId);
        $creditAccount = ChartOfAccount::find($draft->creditAccountId);

        if ($debitAccount === null || ! $debitAccount->is_postable) {
            throw new RuntimeException('Debit account is not postable.');
        }

        if ($creditAccount === null || ! $creditAccount->is_postable) {
            throw new RuntimeException('Credit account is not postable.');
        }
    }
}
