<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentDirection;
use App\Models\Booking;
use App\Models\Deposit;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingService;
use App\Support\Sequences\SequenceGenerator;
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
    public function refundDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postDepositRefunded($deposit, $userId),
        );
    }

    /** @see self::refundDeposit() for when this is safe to call. */
    public function forfeitDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postForfeited($deposit, $userId),
        );
    }

    public function accrueOwnerInstallment(OwnerInstallment $installment, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->installmentPoster->postAccrual($installment, $userId),
        );
    }

    public function assignFine(Fine $fine, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->finePoster->postCustomerLiability($fine, $userId),
        );
    }

    public function approvePayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->payrollPoster->postPayrollApproved($run, $userId),
        );
    }

    public function payPayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->payrollPoster->postPayrollPaid($run, $userId),
        );
    }
}
