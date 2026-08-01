<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Owns the invariants of a car block.
 *
 * A block takes a car off the market for a window that is not a rental. The
 * window must not overlap another block (Postgres EXCLUDE constraint) or a
 * confirmed/active/overdue booking (cross-check trigger) — both are DB-enforced
 * (ADR-002); this service exists so the form surfaces those clashes as readable
 * validation errors instead of a raw constraint error at the user.
 *
 * A block is also a small piece of history: how long the car was actually off
 * the road, and why. Ending a block early goes through {@see endEarly()}, which
 * truncates the window to now — the change lands in the activity log, so
 * "blocked 3 days, released early on the 2nd" stays answerable. A block that
 * has not started yet is not truncated: {@see cancel()} deletes it, because an
 * inverted window (ends_at before starts_at) is not a record of anything that
 * happened.
 */
class CarBlockService
{
    /**
     * Create a block, refusing a window that overlaps an existing block or a
     * live booking on the car.
     *
     * @param array{car_id: int, reason: string, starts_at: string, ends_at: string, maintenance_log_id?: int|null, notes?: string|null} $data
     *
     * @throws RuntimeException when the window clashes with a block or a booking.
     */
    public function create(array $data, User $by): CarBlock
    {
        $this->assertWindowFree(
            Car::findOrFail($data['car_id']),
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
        );

        return CarBlock::create([
            ...$data,
            'created_by_id' => $by->id,
        ]);
    }

    /**
     * Update a block, refusing any window that now clashes with a block or a
     * booking on the car — an extension can overlap a booking made in the
     * meantime. Shortening can only remove clashes, never add them.
     *
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException when the window clashes with a block or a booking.
     */
    public function update(CarBlock $block, array $data): CarBlock
    {
        $this->assertWindowFree(
            Car::findOrFail($data['car_id'] ?? $block->car_id),
            Carbon::parse($data['starts_at'] ?? $block->starts_at),
            Carbon::parse($data['ends_at'] ?? $block->ends_at),
            $block,
        );

        $block->update($data);

        return $block;
    }

    /**
     * End a block that is currently in force: truncate the window to now. The
     * activity log records the change, so the early release is history, not a
     * rewrite.
     *
     * @throws RuntimeException when the block is not currently in force.
     */
    public function endEarly(CarBlock $block): void
    {
        if (! $block->isActiveNow()) {
            throw new RuntimeException(__('car_blocks.errors.not_active'));
        }

        $endsAt = now();

        // The DB guarantees ends_at > starts_at; if the block started this very
        // second, push the end past it rather than fail the constraint.
        if ($endsAt->lte($block->starts_at)) {
            $endsAt = $block->starts_at->copy()->addSecond();
        }

        $block->update(['ends_at' => $endsAt]);
    }

    /**
     * Cancel a block that has not started yet. Deleting a future block is not
     * losing evidence — it never took a car off the road. Ending it instead
     * would leave an inverted window that records nothing.
     *
     * @throws RuntimeException when the block has already started.
     */
    public function cancel(CarBlock $block): void
    {
        if (! $block->isUpcoming()) {
            throw new RuntimeException(__('car_blocks.errors.not_upcoming'));
        }

        $block->delete();
    }

    /**
     * The readable form of the DB guards (ADR-002): the EXCLUDE constraint for
     * block-vs-block and the cross-check trigger for block-vs-booking. Mirrors
     * their half-open-window semantics — [starts_at, ends_at).
     */
    private function assertWindowFree(Car $car, Carbon $startsAt, Carbon $endsAt, ?CarBlock $excluding = null): void
    {
        $blockClash = CarBlock::query()
            ->where('car_id', $car->id)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($excluding !== null, fn ($query) => $query->where('id', '!=', $excluding->id))
            ->exists();

        if ($blockClash) {
            throw new RuntimeException(__('car_blocks.errors.block_clash'));
        }

        $bookingClash = Booking::query()
            ->where('car_id', $car->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Overdue])
            ->where('pickup_at', '<', $endsAt)
            ->where('expected_return_at', '>', $startsAt)
            ->exists();

        if ($bookingClash) {
            throw new RuntimeException(__('car_blocks.errors.booking_clash'));
        }
    }
}
