<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 */
class CarBlock extends Model
{
    use BelongsToBranch, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'car_id',
        'branch_id',
        'reason',
        'starts_at',
        'ends_at',
        'maintenance_log_id',
        'notes',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<MaintenanceLog, $this> */
    public function maintenanceLog(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLog::class);
    }

    /** In force right now: the window has started and not yet ended. */
    public function isActiveNow(): bool
    {
        return $this->starts_at <= now() && $this->ends_at > now();
    }

    /** Not started yet. */
    public function isUpcoming(): bool
    {
        return $this->starts_at > now();
    }

    /** Already over. */
    public function isEnded(): bool
    {
        return $this->ends_at <= now();
    }
}
