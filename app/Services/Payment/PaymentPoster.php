<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Services\Accounting\TransactionDraft;
use App\Services\ReportService;
use App\Support\Money;
use DateTimeImmutable;

class PaymentPoster
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /** @return list<TransactionDraft> */
    public function postPayment(Payment $payment, int $userId): array
    {
        // A `match` rather than a lookup with a `?? '1010'` default: the default meant a
        // payment method nobody had mapped posted quietly to the cash box. An unmapped
        // method is a decision someone has to make, so it throws instead.
        $cashAccountCode = match ($payment->method) {
            PaymentMethod::Cash => '1010',
            PaymentMethod::BankTransfer => '1020',
            PaymentMethod::Ccp => '1030',
            PaymentMethod::BaridiMob => '1040',
            PaymentMethod::Card, PaymentMethod::Cheque => '1050',
            PaymentMethod::Compensation => '2500',
        };

        $drafts = [];
        $occurredOn = new DateTimeImmutable($payment->paid_at->format('Y-m-d'));
        $bookingId = $this->bookingIdFor($payment);

        if ($payment->direction === PaymentDirection::Inbound) {
            $amount = Money::of((string) $payment->amount);
            $outstanding = $this->receivableOutstanding($payment);
            $clearsAr = $amount->lessThan($outstanding) ? $amount : $outstanding;

            // E10–E14: payment clears the outstanding receivable.
            if ($clearsAr->isPositive()) {
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId($cashAccountCode),
                    creditAccountId: $this->resolveId('1110'),
                    amount: $clearsAr->toDecimal(),
                    type: TransactionType::Payment,
                    occurredOn: $occurredOn,
                    description: 'Payment — '.$payment->reference,
                    branchId: $payment->branch_id,
                    bookingId: $bookingId,
                    createdById: $userId,
                    customerId: $payment->customer_id,
                    carOwnerId: $payment->car_owner_id,
                    employeeId: $payment->employee_id,
                    cashSessionId: $payment->cash_session_id,
                    paymentMethod: $payment->method,
                    sourceType: 'payment',
                    sourceId: $payment->id,
                    meta: ['payment_reference' => $payment->reference],
                );
            }

            // E19: the part beyond what is owed is a credit held for the customer,
            // not revenue. Posting it to the receivable would fabricate a credit
            // balance on 1110 that the statements derive differently.
            //
            // It carries the same booking dimension as the E10–E14 leg above. Without
            // it, money taken before the rental is invoiced — the ordinary deposit at
            // booking time — detached from the booking entirely, and the settlement
            // panel reported "paid 0 / outstanding in full" beside a payments list
            // showing the customer's money. `ReportService` nets this account into both
            // figures; it can only do so if the row says which booking it belongs to.
            $overpaid = $amount->minus($clearsAr);

            if ($overpaid->isPositive()) {
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId($cashAccountCode),
                    creditAccountId: $this->resolveId('2500'),
                    amount: $overpaid->toDecimal(),
                    type: TransactionType::Payment,
                    occurredOn: $occurredOn,
                    description: 'Payment on account — '.$payment->reference,
                    branchId: $payment->branch_id,
                    bookingId: $bookingId,
                    createdById: $userId,
                    customerId: $payment->customer_id,
                    carOwnerId: $payment->car_owner_id,
                    employeeId: $payment->employee_id,
                    cashSessionId: $payment->cash_session_id,
                    paymentMethod: $payment->method,
                    sourceType: 'payment',
                    sourceId: $payment->id,
                    meta: ['payment_reference' => $payment->reference, 'overpayment' => true],
                );
            }
        } else {
            // E21, E35: refund / outbound
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId($cashAccountCode),
                amount: (string) $payment->amount,
                type: TransactionType::Payment,
                occurredOn: $occurredOn,
                description: 'Refund — '.$payment->reference,
                branchId: $payment->branch_id,
                bookingId: $bookingId,
                createdById: $userId,
                customerId: $payment->customer_id,
                carOwnerId: $payment->car_owner_id,
                employeeId: $payment->employee_id,
                cashSessionId: $payment->cash_session_id,
                paymentMethod: $payment->method,
                sourceType: 'payment',
                sourceId: $payment->id,
                meta: ['payment_reference' => $payment->reference],
            );
        }

        return $drafts;
    }

    /**
     * The receivable still standing on the dimension this payment settles.
     *
     * When the payment is taken against a booking, that booking's open receivable
     * decides the split; an unallocated customer payment falls back to the customer's
     * whole AR. A payment with no booking and no customer is unattributed money: there
     * is nothing to clear, so the whole amount is a credit balance.
     *
     * The aggregation itself lives in `ReportService`, which is the single home for
     * every sum over `transactions` — this poster asking the ledger directly meant two
     * definitions of "what is owed", and they had already drifted apart (one scoped to
     * 1110, the other to every receivable control account). The ledger is only read
     * here: this decides where the credit lands, it does not write.
     */
    private function receivableOutstanding(Payment $payment): Money
    {
        if ($payment->payable_type === Booking::class && $payment->payable_id !== null) {
            return $this->reports->openReceivableForBooking((int) $payment->payable_id);
        }

        if ($payment->customer_id !== null) {
            return $this->reports->openReceivableForCustomer($payment->customer_id);
        }

        return Money::zero();
    }

    /**
     * The booking dimension the posting matrix requires on E10–E14 and E19.
     *
     * A payment reaches its booking through the `payable` morph, so without this the
     * row carried a customer but no booking, and "what is still owed on booking X"
     * could not be derived from the ledger at all — only "what does this customer owe
     * across every rental they have ever taken".
     */
    private function bookingIdFor(Payment $payment): ?int
    {
        return $payment->payable_type === Booking::class
            ? $payment->payable_id
            : null;
    }

    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }
}
