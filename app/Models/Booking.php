<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\FuelLevel;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property BookingStatus $status
 * @property int|null $car_id
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property string|null $reference
 * @property string $total_amount
 * @property int|null $odometer_out
 * @property int|null $odometer_in
 */
class Booking extends Model
{
    use BelongsToBranch, HasAuditColumns, HasLedgerPostings;

    protected $fillable = [
        'uuid',
        'reference',
        'branch_id',
        'car_id',
        'customer_id',
        'created_by_id',
        'sales_agent_id',
        'status',
        'pickup_at',
        'expected_return_at',
        'actual_pickup_at',
        'actual_return_at',
        'pickup_branch_id',
        'return_branch_id',
        'pickup_location',
        'return_location',
        'daily_rate',
        'days_count',
        'subtotal',
        'extras_total',
        'discount_amount',
        'discount_reason',
        'total_amount',
        'security_deposit_amount',
        'odometer_out',
        'odometer_in',
        'fuel_level_out',
        'fuel_level_in',
        'with_driver',
        'driver_employee_id',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'uuid' => 'string',
            'status' => BookingStatus::class,
            'pickup_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'actual_pickup_at' => 'datetime',
            'actual_return_at' => 'datetime',
            'daily_rate' => 'decimal:2',
            'days_count' => 'integer',
            'subtotal' => 'decimal:2',
            'extras_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'odometer_out' => 'integer',
            'odometer_in' => 'integer',
            'fuel_level_out' => FuelLevel::class,
            'fuel_level_in' => FuelLevel::class,
            'with_driver' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $booking->uuid ??= (string) Str::uuid();
            if (blank($booking->reference)) {
                $booking->reference = 'BK-'.strtoupper(Str::random(10));
            }
        });
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_agent_id');
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function returnBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'return_branch_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    public function conditionReports(): HasMany
    {
        return $this->hasMany(ConditionReport::class);
    }

    public function additionalDrivers(): HasMany
    {
        return $this->hasMany(AdditionalDriver::class);
    }

    public function driverEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_employee_id');
    }

    /** @return HasMany<Deposit> */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /** @return HasMany<Fine> */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function scopeActive($query): void
    {
        $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Overdue]);
    }
}
