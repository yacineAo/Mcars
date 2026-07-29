<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Car> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<CarCategory> */
    public function carCategory(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class);
    }
}
