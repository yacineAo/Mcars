<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use Carbon\CarbonPeriod;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

class BookingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly PricingService $pricing,
        private readonly BookingPoster $poster,
    ) {}

    public function createDraft(
        Car $car,
        Customer $customer,
        CarbonPeriod $period,
        User $createdBy,
        ?int $branchId = null,
    ): Booking {
        $days = (int) ceil($period->getStartDate()->diffInDays($period->getEndDate()));

        return Booking::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'BK-'.strtoupper(Str::random(10)),
            'branch_id' => $branchId ?? $car->branch_id,
            'car_id' => $car->id,
            'customer_id' => $customer->id,
            'created_by_id' => $createdBy->id,
            'status' => BookingStatus::Draft,
            'pickup_at' => $period->getStartDate(),
            'expected_return_at' => $period->getEndDate(),
            'daily_rate' => $car->daily_rate,
            'days_count' => $days,
            'subtotal' => number_format((float) $car->daily_rate * $days, 2, '.', ''),
            'total_amount' => number_format((float) $car->daily_rate * $days, 2, '.', ''),
        ]);
    }

    public function confirm(Booking $booking, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking): Booking {
            $booking->status = BookingStatus::Confirmed;
            $booking->save();

            return $booking->fresh();
        });
    }

    public function checkOut(Booking $booking, array $handoverData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $handoverData, $by): Booking {
            $booking->status = BookingStatus::Active;
            $booking->actual_pickup_at = $handoverData['actual_pickup_at'] ?? now();
            $booking->odometer_out = $handoverData['odometer_out'] ?? null;
            $booking->fuel_level_out = $handoverData['fuel_level_out'] ?? null;
            $booking->save();

            // Post E02–E04 to the ledger
            $drafts = $this->poster->postRentalRevenue($booking, $by->id);
            $this->accounting->postMany(...$drafts);

            // Update car status
            $booking->car->update(['status' => CarStatus::Rented]);

            return $booking->fresh();
        });
    }

    public function checkIn(Booking $booking, array $returnData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $returnData): Booking {
            $booking->status = BookingStatus::Completed;
            $booking->actual_return_at = $returnData['actual_return_at'] ?? now();
            $booking->odometer_in = $returnData['odometer_in'] ?? null;
            $booking->fuel_level_in = $returnData['fuel_level_in'] ?? null;
            $booking->save();

            // Update car status
            $booking->car->update(['status' => CarStatus::Available]);

            return $booking->fresh();
        });
    }

    public function checkInWithCharges(Booking $booking, array $returnData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $returnData, $by): Booking {
            $booking->status = BookingStatus::Completed;
            $booking->actual_return_at = $returnData['actual_return_at'] ?? now();
            $booking->odometer_in = $returnData['odometer_in'] ?? null;
            $booking->fuel_level_in = $returnData['fuel_level_in'] ?? null;
            $booking->save();

            $checkin = $booking->conditionReports()
                ->where('type', 'checkin')
                ->latest()
                ->first();

            if ($checkin) {
                $quote = $this->pricing->closeout($booking, $checkin);
                $drafts = $this->poster->postCloseoutCharges($booking, $quote, $by->id);
                if (! empty($drafts)) {
                    $this->accounting->postMany(...$drafts);
                }
            }

            $booking->car->update(['status' => CarStatus::Available]);

            return $booking->fresh();
        });
    }

    public function cancel(Booking $booking, string $reason, User $by): Booking
    {
        if ($booking->status->isTerminal()) {
            throw new RuntimeException('Cannot cancel a booking that is already '.$booking->status->value);
        }

        $booking->status = BookingStatus::Cancelled;
        $booking->cancellation_reason = $reason;
        $booking->cancelled_at = now();
        $booking->cancelled_by_id = $by->id;
        $booking->save();

        return $booking;
    }
}
