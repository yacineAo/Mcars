<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $car_id
 * @property int|null $car_category_id
 * @property MaintenanceType $task_type
 * @property int|null $interval_km
 * @property int|null $interval_days
 * @property CarbonInterface|null $last_done_at
 * @property int|null $last_done_odometer
 * @property CarbonInterface|null $next_due_at
 * @property int|null $next_due_odometer
 * @property int|null $alert_km_before
 * @property int|null $alert_days_before
 * @property bool $is_active
 */
class MaintenanceSchedule extends Model
{
    use HasAuditColumns, LogsActivity;

    protected $fillable = [
        'car_id',
        'car_category_id',
        'task_type',
        'interval_km',
        'interval_days',
        'last_done_at',
        'last_done_odometer',
        'next_due_at',
        'next_due_odometer',
        'alert_km_before',
        'alert_days_before',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'task_type' => MaintenanceType::class,
            'interval_km' => 'integer',
            'interval_days' => 'integer',
            'last_done_at' => 'date',
            'last_done_odometer' => 'integer',
            'next_due_at' => 'date',
            'next_due_odometer' => 'integer',
            'alert_km_before' => 'integer',
            'alert_days_before' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<CarCategory, $this> */
    public function carCategory(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class);
    }
}
