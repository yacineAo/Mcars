<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class BookingPoster
{
    public function postRentalRevenue(Booking $booking, int $userId): array
    {
        $drafts = [];
        $occurredOn = new DateTimeImmutable($booking->actual_pickup_at?->format('Y-m-d') ?? 'now');
        $branchId = $booking->branch_id;

        // E02: Rental invoiced
        $drafts[] = new TransactionDraft(
            debitAccountId: $this->resolveId('1110'),
            creditAccountId: $this->resolveId('4010'),
            amount: $booking->subtotal,
            type: TransactionType::RentalRevenue,
            occurredOn: $occurredOn,
            description: 'Rental revenue — '.$booking->reference,
            branchId: $branchId,
            createdById: $userId,
            carId: $booking->car_id,
            bookingId: $booking->id,
            customerId: $booking->customer_id,
            sourceType: 'booking',
            sourceId: $booking->id,
            meta: ['booking_reference' => $booking->reference],
        );

        // E04: Extras invoiced
        if ((float) $booking->extras_total > 0) {
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId('4020'),
                amount: $booking->extras_total,
                type: TransactionType::ExtrasRevenue,
                occurredOn: $occurredOn,
                description: 'Extras — '.$booking->reference,
                branchId: $branchId,
                createdById: $userId,
                carId: $booking->car_id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                sourceType: 'booking',
                sourceId: $booking->id,
                meta: ['booking_reference' => $booking->reference],
            );
        }

        return $drafts;
    }

    /**
     * E72 — additional days invoiced when a live rental is extended.
     *
     * Revenue is recognised for the full contracted amount at pickup (E02). Extending
     * the contract raises that amount, so the extra days post as their own E02-shaped
     * row rather than by editing the original: the ledger is append-only, and the
     * original row is what the customer's contract says at the time it was signed.
     *
     * `occurredOn` is today, not the pickup date — the extension is agreed now, and
     * backdating it would move revenue into a period that may already be reported.
     *
     * The day count and the reason ride in `meta` because this row is the only
     * un-editable record of them: `bookings.notes` is free text staff can type over.
     *
     * @return list<TransactionDraft>
     */
    public function postExtensionRevenue(
        Booking $booking,
        string $additionalAmount,
        int $userId,
        int $additionalDays,
        ?string $reason = null,
    ): array {
        return [new TransactionDraft(
            debitAccountId: $this->resolveId('1110'),
            creditAccountId: $this->resolveId('4010'),
            amount: $additionalAmount,
            type: TransactionType::RentalRevenue,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Rental extension — '.$booking->reference,
            branchId: $booking->branch_id,
            createdById: $userId,
            carId: $booking->car_id,
            bookingId: $booking->id,
            customerId: $booking->customer_id,
            sourceType: 'booking',
            sourceId: $booking->id,
            meta: [
                'booking_reference' => $booking->reference,
                'extension' => true,
                'additional_days' => $additionalDays,
                'extended_to' => $booking->expected_return_at?->toIso8601String(),
                'reason' => $reason,
            ],
        )];
    }

    public function postCloseoutCharges(Booking $booking, CloseoutQuote $quote, int $userId): array
    {
        $drafts = [];
        $occurredOn = new DateTimeImmutable($booking->actual_return_at?->format('Y-m-d') ?? 'now');
        $branchId = $booking->branch_id;

        // E05: Late return fee
        if ((float) $quote->lateFee > 0) {
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId('4030'),
                amount: $quote->lateFee,
                type: TransactionType::LateFee,
                occurredOn: $occurredOn,
                description: 'Late return — '.$booking->reference,
                branchId: $branchId,
                createdById: $userId,
                carId: $booking->car_id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                sourceType: 'booking',
                sourceId: $booking->id,
                meta: ['booking_reference' => $booking->reference],
            );
        }

        // E06: Excess mileage
        if ((float) $quote->extraKmFee > 0) {
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId('4040'),
                amount: $quote->extraKmFee,
                type: TransactionType::ExcessMileage,
                occurredOn: $occurredOn,
                description: 'Excess mileage — '.$booking->reference,
                branchId: $branchId,
                createdById: $userId,
                carId: $booking->car_id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                sourceType: 'booking',
                sourceId: $booking->id,
                meta: ['booking_reference' => $booking->reference],
            );
        }

        // E07: Fuel shortfall
        if ((float) $quote->fuelShortfall > 0) {
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId('4050'),
                amount: $quote->fuelShortfall,
                type: TransactionType::FuelRecharge,
                occurredOn: $occurredOn,
                description: 'Fuel shortfall — '.$booking->reference,
                branchId: $branchId,
                createdById: $userId,
                carId: $booking->car_id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                sourceType: 'booking',
                sourceId: $booking->id,
                meta: ['booking_reference' => $booking->reference],
            );
        }

        // E08: Cleaning charge
        if ((float) $quote->cleaningFee > 0) {
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId('4080'),
                amount: $quote->cleaningFee,
                type: TransactionType::CleaningFee,
                occurredOn: $occurredOn,
                description: 'Cleaning fee — '.$booking->reference,
                branchId: $branchId,
                createdById: $userId,
                carId: $booking->car_id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                sourceType: 'booking',
                sourceId: $booking->id,
                meta: ['booking_reference' => $booking->reference],
            );
        }

        return $drafts;
    }

    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }
}
