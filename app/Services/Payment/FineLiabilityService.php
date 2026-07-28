<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Fine;

class FineLiabilityService
{
    /**
     * Propose liability by matching violation time against active contracts.
     * Returns the proposed liability and pre-filled booking/contract/customer IDs.
     */
    public function proposeLiability(Fine $fine): Fine
    {
        $violationAt = $fine->violation_at;

        $activeBooking = Booking::query()
            ->where('car_id', $fine->car_id)
            ->where('status', 'active')
            ->where('actual_pickup_at', '<=', $violationAt)
            ->where(function ($q) use ($violationAt) {
                $q->whereNull('actual_return_at')
                    ->orWhere('actual_return_at', '>=', $violationAt);
            })
            ->first();

        if ($activeBooking) {
            $fine->liability = 'customer';
            $fine->booking_id = $activeBooking->id;
            $fine->customer_id = $activeBooking->customer_id;

            if ($activeBooking->contract) {
                $fine->contract_id = $activeBooking->contract->id;
            }
        } else {
            $fine->liability = 'company';
        }

        $fine->status = 'pending_review';

        return $fine;
    }

    public function confirmLiability(Fine $fine, string $liability, int $userId): Fine
    {
        $fine->liability = $liability;
        $fine->liability_determined_by_id = $userId;
        $fine->liability_determined_at = now();
        $fine->status = $liability === 'customer' ? 'assigned_to_customer' : 'paid_by_company';
        $fine->save();

        return $fine->fresh();
    }
}
