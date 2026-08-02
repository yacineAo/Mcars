<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AdvanceStatus;
use App\Enums\CommissionStatus;
use App\Enums\PaymentDirection;
use App\Enums\PayrollStatus;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Deposit;
use App\Models\EmployeeAdvance;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use App\Support\Sequences\SequenceGenerator;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class PaymentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly PaymentPoster $paymentPoster,
        private readonly DepositPoster $depositPoster,
        private readonly OwnerInstallmentPoster $installmentPoster,
        private readonly FinePoster $finePoster,
        private readonly PayrollPoster $payrollPoster,
        private readonly SequenceGenerator $sequences,
    ) {}

    /** @return Collection<int, Transaction> */
    public function recordPayment(Payment $payment, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->paymentPoster->postPayment($payment, $userId),
        );
    }

    /**
     * Take money against a booking: build the payment and post it, as one step.
     *
     * The shape of a customer payment — inbound, the booking as `payable`, the
     * booking's branch and customer, DZD — is domain knowledge and lives here. It used
     * to be assembled inside a Filament action closure, which meant a second caller (an
     * import, a test, the payments screen) could construct it differently and nothing
     * would notice until the ledger disagreed with the bookings list.
     *
     * Wrapped in a transaction because the two halves are worthless apart: a `payments`
     * row that never posted is invisible to every balance in the system, and the
     * customer goes on showing as owing money they have handed over.
     *
     * @param array{amount: mixed, method: mixed, financial_account_id?: mixed, notes?: mixed} $data
     */
    public function recordBookingPayment(Booking $booking, array $data, int $userId): Payment
    {
        return $this->db->transaction(function () use ($booking, $data, $userId): Payment {
            $payment = Payment::create([
                // `payments.reference` is unique and NOT NULL. The action that used to
                // build this never set it, so recording a payment from a booking threw
                // a not-null violation every time. Numbered through SequenceGenerator
                // rather than a random suffix: it is a document number, it must not
                // collide, and this is already inside the transaction it requires.
                'reference' => $this->sequences->next(
                    'payment',
                    $booking->branch_id,
                    $booking->branch?->code,
                ),
                'branch_id' => $booking->branch_id,
                'direction' => PaymentDirection::Inbound,
                'payable_type' => Booking::class,
                'payable_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'method' => $data['method'],
                'amount' => $data['amount'],
                'currency' => 'DZD',
                'paid_at' => now(),
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'received_by_id' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->recordPayment($payment, $userId);

            return $payment;
        });
    }

    /** @return Collection<int, Transaction> */
    public function holdDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postDepositReceived($deposit, $userId),
        );
    }

    /**
     * Posting-only helpers, correct solely for a deposit nothing has been
     * deducted from: they post the whole amount and never touch the deposit's
     * status or settlement columns.
     *
     * `DepositService::refund()` / `::forfeit()` are the real entry points —
     * they net off the deductions first and settle the row. Prefer those.
     */
    /** @return Collection<int, Transaction> */
    public function refundDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postDepositRefunded($deposit, $userId),
        );
    }

    /** @see self::refundDeposit() for when this is safe to call. */
    /** @return Collection<int, Transaction> */
    public function forfeitDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postForfeited($deposit, $userId),
        );
    }

    /** @return Collection<int, Transaction> */
    public function accrueOwnerInstallment(OwnerInstallment $installment, int $userId): Collection
    {
        // The pointer stamp is part of the accrual, not a follow-up: posted
        // without it, a crash between the commit and the update would leave E32
        // on the ledger while the row still reads as unaccrued. One transaction
        // keeps the posting and the pointer atomic (postMany nests safely).
        return $this->db->transaction(function () use ($installment, $userId): Collection {
            $transactions = $this->accounting->postMany(
                $this->installmentPoster->postAccrual($installment, $userId),
            );

            // E32 is the row the "unaccrued" queue and the accrued indicator
            // read: `accrual_transaction_id IS NULL` means "still to accrue".
            if ($installment->accrual_transaction_id === null) {
                $installment->update([
                    'accrual_transaction_id' => $transactions->first()?->id,
                ]);
            }

            return $transactions;
        });
    }

    /** @return Collection<int, Transaction> */
    public function assignFine(Fine $fine, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->finePoster->postCustomerLiability($fine, $userId),
        );
    }

    /** E50: Fine received, company liable — an absorbed expense. */
    /** @return Collection<int, Transaction> */
    public function absorbFine(Fine $fine, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->finePoster->postCompanyLiability($fine, $userId),
        );
    }

    /**
     * E57-E59, E62: approving a run records salaries, employer contributions
     * and commissions as payables, and recovers the advances — all in the same
     * transaction as the status flip, so a crash cannot leave the accrual on
     * the ledger with the run still draft (which would make the run neither
     * re-approvable nor payable).
     *
     * The guard is the invariant: only a draft run may be approved, and the
     * double-post risk is closed by the lock — a second caller (a stale tab,
     * an import) finds the run already approved and is refused.
     */
    /** @return Collection<int, Transaction> */
    public function approvePayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->db->transaction(function () use ($run, $userId): Collection {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== PayrollStatus::Draft) {
                throw new DomainException('Only a draft payroll run can be approved.');
            }

            $transactions = $this->accounting->postMany(
                ...$this->payrollPoster->postPayrollApproved($run, $userId),
            );

            $run->update([
                'status' => PayrollStatus::Approved,
                'approved_by_id' => $userId,
                'approved_at' => now(),
            ]);
            $run->items()->update(['status' => 'approved']);

            return $transactions;
        });
    }

    /**
     * E60: paying the run is the largest posting the business makes each
     * month, so this is the highest-consequence double-post in the system. The
     * status flip, the item statuses and the posting share one transaction and
     * one lock: only an approved run can be paid, and a second caller finds it
     * already paid and is refused.
     */
    /** @return Collection<int, Transaction> */
    public function payPayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->db->transaction(function () use ($run, $userId): Collection {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== PayrollStatus::Approved) {
                throw new DomainException('Only an approved payroll run can be paid.');
            }

            $itemIds = $run->items()->pluck('id');

            $transactions = $this->accounting->postMany(
                ...$this->payrollPoster->postPayrollPaid($run, $userId),
            );

            $run->update([
                'status' => PayrollStatus::Paid,
                'paid_at' => now(),
            ]);
            $run->items()->update(['status' => 'paid', 'paid_at' => now()]);

            // The swept commissions are settled by this payment: marking them
            // paid seals them against the sweep queue and against amendment.
            Commission::whereIn('payroll_item_id', $itemIds)
                ->update(['status' => CommissionStatus::Paid->value]);

            return $transactions;
        });
    }

    /**
     * E61: approving an advance is authorising the payout — the status flip and
     * the ledger posting land in one transaction, so a crash cannot leave cash
     * out of the till with the advance still reading `requested` (or the
     * reverse: posted and still requestable).
     *
     * The guard is the invariant: only a `requested` advance may be approved,
     * and never one granted to yourself — the clearest self-dealing path in
     * the panel is the exact flow this closes.
     */
    /** @return Collection<int, Transaction> */
    public function approveAdvance(EmployeeAdvance $advance, int $userId): Collection
    {
        return $this->db->transaction(function () use ($advance, $userId): Collection {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->status !== AdvanceStatus::Requested) {
                throw new DomainException('Only a requested advance can be approved.');
            }

            $employee = $advance->employee;

            if ($employee?->user_id !== null && (int) $employee->user_id === $userId) {
                throw new DomainException('An advance to your own employee record cannot be approved.');
            }

            $transactions = $this->accounting->postMany(
                $this->payrollPoster->postAdvance(
                    (string) $advance->amount,
                    $advance->employee_id,
                    $advance->branch_id,
                    $userId,
                ),
            );

            $advance->update(['status' => AdvanceStatus::Outstanding]);

            return $transactions;
        });
    }

    /**
     * A requested advance the manager does not sanction: status flip only, no
     * ledger. The guard needs the same transaction boundary as the approval:
     * `lockForUpdate()` outside one holds no lock in PostgreSQL autocommit, and
     * a reject racing an approve could otherwise overwrite `outstanding` (with
     * E61 already on the ledger) back to `rejected`.
     */
    public function rejectAdvance(EmployeeAdvance $advance): EmployeeAdvance
    {
        return $this->db->transaction(function () use ($advance): EmployeeAdvance {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->status !== AdvanceStatus::Requested) {
                throw new DomainException('Only a requested advance can be rejected.');
            }

            $advance->update(['status' => AdvanceStatus::Rejected]);

            return $advance->fresh();
        });
    }
}
