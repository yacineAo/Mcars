<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\CarCategory;
use Carbon\CarbonPeriod;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class BookingAvailabilityService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function isAvailable(Car $car, CarbonPeriod $period, ?Booking $excluding = null): bool
    {
        return $this->conflictsFor($car, $period, $excluding)->isEmpty();
    }

    public function availableCars(CarbonPeriod $period, ?CarCategory $category = null, ?Branch $branch = null): Collection
    {
        // `is_active` withdraws a car from availability. Decided in docs/resource/02-car.md:
        // the toggle on the car page said "active" and did nothing, so a car taken off the
        // road was still offered by search. Status covers *why* a car is unavailable today;
        // is_active covers "not part of the rentable fleet at all".
        $query = Car::query()
            ->where('status', CarStatus::Available->value)
            ->where('is_active', true);

        if ($category !== null) {
            $query->where('car_category_id', $category->id);
        }

        if ($branch !== null) {
            $query->where('branch_id', $branch->id);
        }

        return $query->get()->filter(fn (Car $car): bool => $this->isAvailable($car, $period));
    }

    public function conflictsFor(Car $car, CarbonPeriod $period, ?Booking $excluding = null): Collection
    {
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        $bookingConflicts = Booking::query()
            ->where('car_id', $car->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Overdue])
            ->where(function ($q) use ($start, $end): void {
                $q->where('pickup_at', '<', $end)
                    ->where('expected_return_at', '>', $start);
            })
            ->when($excluding !== null, fn ($q) => $q->where('id', '!=', $excluding->id))
            ->get();

        $blockConflicts = CarBlock::query()
            ->where('car_id', $car->id)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get();

        return $bookingConflicts->concat($blockConflicts);
    }

    public function calendarFeed(CarbonPeriod $period, array $filters = []): array
    {
        $bookings = Booking::query()
            ->whereBetween('pickup_at', [$period->getStartDate(), $period->getEndDate()])
            ->with(['car', 'customer'])
            ->get();

        $blocks = CarBlock::query()
            ->whereBetween('starts_at', [$period->getStartDate(), $period->getEndDate()])
            ->with('car')
            ->get();

        $feed = [];

        foreach ($bookings as $booking) {
            $feed[] = [
                'id' => 'booking-'.$booking->id,
                'resourceId' => (string) $booking->car_id,
                // `->full_name` does not exist on Customer, so every event in the feed
                // was titled "N/A". Only visible once Booking declared the relation's
                // type — the property access was previously against a bare Model.
                'title' => $booking->customer?->displayName() ?? 'N/A',
                'start' => $booking->pickup_at->toIso8601String(),
                'end' => $booking->expected_return_at->toIso8601String(),
                'color' => $booking->status->getColor(),
                'type' => 'booking',
                'status' => $booking->status->value,
                'url' => "/admin/bookings/{$booking->id}/edit",
            ];
        }

        foreach ($blocks as $block) {
            $feed[] = [
                'id' => 'block-'.$block->id,
                'resourceId' => (string) $block->car_id,
                'title' => __('enums.block_reason.'.$block->reason),
                'start' => $block->starts_at->toIso8601String(),
                'end' => $block->ends_at->toIso8601String(),
                'color' => '#6b7280',
                'type' => 'block',
                'reason' => $block->reason,
            ];
        }

        return $feed;
    }

    public function confirm(Booking $booking): void
    {
        $this->db->transaction(function () use ($booking): void {
            $booking->status = BookingStatus::Confirmed;
            $booking->save();
        });
    }

    /*
     * `extend()` used to live here. It had no callers, did money arithmetic in float,
     * never checked that the car was free for the additional window, and — worst —
     * raised `total_amount` without posting anything, so the extra days were invoiced
     * nowhere and the ledger silently disagreed with the booking. Extending is an
     * orchestration with a ledger posting, so it belongs in BookingService::extend().
     */
}
