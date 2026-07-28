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
