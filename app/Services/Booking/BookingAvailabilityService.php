<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\CarCategory;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use RuntimeException;

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
        $query = Car::query()->where('status', 'available');

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
                'title' => $booking->customer?->full_name ?? 'N/A',
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

    public function extend(Booking $booking, Carbon $newReturn): void
    {
        if ($booking->status !== BookingStatus::Active) {
            throw new RuntimeException('Only active bookings can be extended.');
        }

        $booking->expected_return_at = $newReturn;
        $booking->days_count = (int) ceil($booking->pickup_at->diffInDays($newReturn));
        $booking->total_amount = $booking->daily_rate * $booking->days_count + $booking->extras_total - $booking->discount_amount;
        $booking->save();
    }
}
