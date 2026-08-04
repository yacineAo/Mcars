<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\FuelLevel;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property BookingStatus $status
 * @property int|null $car_id
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property string|null $reference
 * @property CarbonInterface|null $pickup_at
 * @property CarbonInterface|null $expected_return_at
 * @property CarbonInterface|null $actual_pickup_at
 * @property CarbonInterface|null $actual_return_at
 * @property string $daily_rate
 * @property int $days_count
 * @property string $subtotal
 * @property string $extras_total
 * @property string $discount_amount
 * @property string $total_amount
 * @property string $security_deposit_amount
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property int|null $odometer_out
 * @property int|null $odometer_in
 * @property FuelLevel|null $fuel_level_out
 * @property FuelLevel|null $fuel_level_in
 * @property-read Car|null $car
 * @property-read Customer|null $customer
 */
class Booking extends Model
{
    use BelongsToBranch, HasAuditColumns, HasLedgerPostings, LogsActivity;

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

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_agent_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function returnBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'return_branch_id');
    }

    /** @return HasOne<Contract, $this> */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    /** @return MorphMany<PaymentSchedule, $this> */
    public function paymentSchedules(): MorphMany
    {
        return $this->morphMany(PaymentSchedule::class, 'schedulable');
    }

    /** @return HasMany<BookingExtra, $this> */
    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /** @return HasMany<ConditionReport, $this> */
    public function conditionReports(): HasMany
    {
        return $this->hasMany(ConditionReport::class);
    }

    /** @return HasMany<AdditionalDriver, $this> */
    public function additionalDrivers(): HasMany
    {
        return $this->hasMany(AdditionalDriver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function driverEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_employee_id');
    }

    /** @return HasMany<Deposit, $this> */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /** @return HasMany<Fine, $this> */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    /**
     * Money taken against this booking.
     *
     * A payment points at its booking through the `payable` morph rather than a
     * `booking_id`, because the same table also carries owner and payroll payments.
     *
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Overdue]);
    }

    /**
     * Out on rental and past its expected return.
     *
     * `Overdue` is included alongside `Active` because nothing flips the status
     * automatically — a booking becomes late by the clock passing, not by anything
     * writing to the row. Matches `OverdueReturnsTable`, which is the same question
     * asked from the dashboard.
     *
     * @param Builder<self> $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query
            ->whereIn('status', [BookingStatus::Active, BookingStatus::Overdue])
            ->where('expected_return_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status->is(BookingStatus::Active, BookingStatus::Overdue)
            && $this->expected_return_at !== null
            && $this->expected_return_at->isPast();
    }

    /**
     * Whether the rental has begun — the point revenue is posted (matrix E02).
     *
     * Past this line the booking's car, customer, dates and pricing are referenced by
     * append-only ledger rows, so editing them desynchronises the two with no trace.
     *
     * `status` is nullable here even though the column itself is NOT NULL with a DB
     * default: a transient, unsaved `Booking` instance — which Filament's create-page
     * closures can be evaluated against mid-form, before anything is persisted — has no
     * attribute value yet. A record with no status cannot have started.
     *
     * The `@property BookingStatus $status` docblock above is deliberately schema-true
     * (every *persisted* row has one), so PHPStan reads this null check as dead — it is
     * not; it is the one path that docblock does not model. Same false-positive class as
     * README finding 6.
     */
    public function hasStarted(): bool
    {
        return $this->status?->is( // @phpstan-ignore nullCoalesce.expr, nullsafe.neverNull
            BookingStatus::Active,
            BookingStatus::Overdue,
            BookingStatus::Completed,
        ) ?? false;
    }
}
