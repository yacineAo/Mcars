<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BlockReason;
use App\Models\Car;
use App\Models\CarBlock;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class BlockCarService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
    ) {}

    public function block(Car $car, BlockReason $reason, Carbon $startsAt, Carbon $endsAt, ?string $notes = null): CarBlock
    {
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('Block end must be after start.');
        }

        $period = CarbonPeriod::create($startsAt, $endsAt);

        if ($this->availability->conflictsFor($car, $period)->isNotEmpty()) {
            throw new RuntimeException('Cannot block car: it has conflicting bookings or blocks in this period.');
        }

        return $car->blocks()->create([
            'branch_id' => $car->branch_id,
            'reason' => $reason,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'notes' => $notes,
            'created_by_id' => Auth::id(),
        ]);
    }
}
