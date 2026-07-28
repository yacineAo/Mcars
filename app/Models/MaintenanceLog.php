<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceLog extends Model
{
    use BelongsToBranch, HasAuditColumns, SoftDeletes;

    protected $fillable = [
        'car_id',
        'branch_id',
        'vendor_id',
        'type',
        'status',
        'scheduled_for',
        'started_at',
        'completed_at',
        'odometer_at_service',
        'cost_parts',
        'cost_labour',
        'total_cost',
        'invoice_number',
        'next_due_date',
        'next_due_odometer',
        'performed_by_id',
        'description',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'status' => MaintenanceStatus::class,
            'scheduled_for' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'odometer_at_service' => 'integer',
            'cost_parts' => 'decimal:2',
            'cost_labour' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'next_due_date' => 'date',
            'next_due_odometer' => 'integer',
        ];
    }

    /** @return BelongsTo<Car> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<Vendor> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
