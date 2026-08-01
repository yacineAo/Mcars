<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\DepositStatus;
use App\Enums\TransactionType;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Services\Accounting\AccountingService;
use App\Support\Money;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class DepositService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly DepositPoster $poster,
    ) {}

    public function hold(Deposit $deposit, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $userId) {
            $deposit->status = DepositStatus::Held;
            $deposit->save();

            $this->accounting->postMany(
                $this->poster->postDepositReceived($deposit, $userId),
            );

            return $deposit->fresh();
        });
    }

    /**
     * What the business still owes the customer: the deposit, less every
     * deduction drawn from it, less anything already handed back.
     *
     * The one definition. It was written three times — the table column, the
     * view page and this service each subtracted their own way, in floats — and
     * three implementations of the same figure is three chances to disagree.
     *
     * Refunds count because a partial refund leaves no deduction row behind. Its
     * only trace is the E31 posting, so a balance derived from deductions alone
     * would still show the money as owed and let a second refund pay it out
     * twice.
     *
     * Authoritative: it re-reads both, so a write path sees the balance as it is
     * now, not as it was when some page rendered.
     */
    public function remainingBalance(Deposit $deposit): Money
    {
        return $this->remainingBalanceFrom(
            $deposit,
            (string) $deposit->deductions()->sum('amount'),
            (string) $deposit->ledgerTransactions()->where('type', TransactionType::DepositRefund)->sum('amount'),
        );
    }

    /**
     * The same arithmetic over sums the caller already has — the `withSum` eager
     * loads on the deposits table, which keep the balance column off the per-row
     * query path.
     *
     * Display only. Those aggregates are as old as the page they were loaded
     * for, so a write path must ask remainingBalance() instead. Both sums are
     * required: defaulting either to zero would silently overstate the balance.
     */
    public function remainingBalanceFrom(Deposit $deposit, string $totalDeducted, string $totalRefunded): Money
    {
        return Money::of((string) $deposit->amount)
            ->minus(Money::of($totalDeducted))
            ->minus(Money::of($totalRefunded));
    }

    /**
     * Build the deduction row from operator input and apply it.
     *
     * The row's shape — including created_by_id — is assembled here, not in the
     * UI, so every caller records the same evidence. The cap check lives in
     * deduct(): a deduction that would exceed the deposit is refused, so the
     * operator sees why rather than creating a negative balance.
     *
     * @param array{reason: string, amount: string|float|int, description?: ?string} $data
     */
    public function deductFromData(Deposit $deposit, array $data, int $userId): Deposit
    {
        $deduction = new DepositDeduction([
            'deposit_id' => $deposit->id,
            'reason' => $data['reason'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'created_by_id' => $userId,
        ]);

        return $this->deduct($deposit, $deduction, $userId);
    }

    public function deduct(Deposit $deposit, DepositDeduction $deduction, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $deduction, $userId) {
            $deduction->save();

            // The saved row is included in the sum, so this asks the question that
            // matters: would the deposit be overdrawn *with* this deduction on it?
            // The throw rolls the transaction back, taking the row with it.
            $remaining = $this->remainingBalance($deposit);

            if ($remaining->isNegative()) {
                throw new RuntimeException('Deductions cannot exceed the deposit amount.');
            }

            $this->accounting->postMany(
                $this->poster->postDeduction(
                    reason: $deduction->reason->value,
                    amount: (string) $deduction->amount,
                    depositId: $deposit->id,
                    bookingId: $deposit->booking_id,
                    customerId: $deposit->customer_id,
                    branchId: $deposit->branch_id,
                    userId: $userId,
                ),
            );

            $deposit->status = $remaining->isPositive()
                ? DepositStatus::PartiallyRefunded
                : DepositStatus::Forfeited;

            $deposit->save();

            return $deposit->fresh();
        });
    }

    /**
     * Return money to the customer and clear that much of the liability.
     *
     * `$amount` null means "everything still owed". Anything else is checked
     * against the remaining balance rather than the original deposit: 2100 only
     * holds what has not already been deducted, so refunding the full deposit
     * after a deduction would push the liability account negative and credit
     * cash that never left the till (E31, docs/05-accounting-model.md).
     */
    public function refund(Deposit $deposit, ?string $amount, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $amount, $userId) {
            $remaining = $this->remainingBalance($deposit);
            $refund = $amount !== null ? Money::of($amount) : $remaining;

            if (! $refund->isPositive()) {
                throw new DomainException('Nothing to refund.');
            }

            if ($refund->greaterThan($remaining)) {
                throw new DomainException('A refund cannot exceed the deposit’s remaining balance.');
            }

            $this->accounting->postMany(
                $this->poster->postDepositRefunded($deposit, $userId, $refund->toDecimal()),
            );

            // A refund short of the remainder leaves the deposit open: money is
            // still owed, so it is not settled and must stay actionable.
            $settled = $refund->equals($remaining);

            $deposit->status = $settled ? DepositStatus::Refunded : DepositStatus::PartiallyRefunded;

            if ($settled) {
                $deposit->settled_at = now();
                $deposit->settled_by_id = $userId;
            }

            $deposit->save();

            return $deposit->fresh();
        });
    }

    /**
     * Keep what is left of the deposit instead of returning it.
     *
     * Only the remainder is forfeited. Whatever was already deducted has been
     * booked to its own revenue account and is no longer part of the liability
     * (E29).
     */
    public function forfeit(Deposit $deposit, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $userId) {
            $remaining = $this->remainingBalance($deposit);

            if (! $remaining->isPositive()) {
                throw new DomainException('Nothing left to forfeit.');
            }

            $this->accounting->postMany(
                $this->poster->postForfeited($deposit, $userId, $remaining->toDecimal()),
            );

            $deposit->status = DepositStatus::Forfeited;
            $deposit->settled_at = now();
            $deposit->settled_by_id = $userId;
            $deposit->save();

            return $deposit->fresh();
        });
    }
}
