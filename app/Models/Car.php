<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BodyType;
use App\Enums\CarStatus;
use App\Enums\FuelType;
use App\Enums\OwnershipType;
use App\Enums\TransmissionType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property int|null $car_owner_id
 * @property CarStatus $status
 * @property OwnershipType $ownership_type
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $registration_number
 * @property string $daily_rate
 * @property string|null $extra_km_price
 * @property string|null $late_hour_fee
 * @property int|null $mileage_limit_per_day
 * @property int|null $odometer
 * @property string|null $security_deposit_amount
 * @property CarbonInterface|null $odometer_updated_at
 * @property CarbonInterface|null $purchase_date
 * @property CarbonInterface|null $created_at
 * @property bool $is_active
 */
class Car extends Model implements HasMedia
{
    /** @use HasFactory<CarFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'car_category_id',
        'car_owner_id',
        'ownership_type',
        'brand',
        'model',
        'trim',
        'year',
        'color',
        'body_type',
        'transmission',
        'fuel_type',
        'seats',
        'doors',
        'chassis_number',
        'engine_number',
        'registration_number',
        'registration_date',
        'status',
        'odometer',
        'odometer_updated_at',
        'fuel_level',
        'daily_rate',
        'weekly_rate',
        'monthly_rate',
        'security_deposit_amount',
        'mileage_limit_per_day',
        'extra_km_price',
        'late_hour_fee',
        'purchase_date',
        'purchase_price',
        'current_value',
        'insurance_expiry_date',
        'technical_inspection_expiry_date',
        'registration_expiry_date',
        'road_tax_expiry_date',
        'gps_device_id',
        'gps_provider',
        'notes',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'ownership_type' => OwnershipType::class,
            'status' => CarStatus::class,
            'body_type' => BodyType::class,
            'transmission' => TransmissionType::class,
            'fuel_type' => FuelType::class,
            'year' => 'integer',
            'seats' => 'integer',
            'doors' => 'integer',
            'odometer' => 'integer',
            'mileage_limit_per_day' => 'integer',
            'daily_rate' => 'decimal:2',
            'weekly_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'extra_km_price' => 'decimal:2',
            'late_hour_fee' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'current_value' => 'decimal:2',
            'registration_date' => 'date',
            'purchase_date' => 'date',
            'insurance_expiry_date' => 'date',
            'technical_inspection_expiry_date' => 'date',
            'registration_expiry_date' => 'date',
            'road_tax_expiry_date' => 'date',
            'odometer_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<CarCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class, 'car_category_id');
    }

    /** @return BelongsTo<CarOwner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class, 'car_owner_id');
    }

    /** @return HasMany<CarOwnershipAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(CarOwnershipAgreement::class);
    }

    /** @return HasMany<CarDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CarDocument::class);
    }

    /** @return HasMany<MaintenanceLog, $this> */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    /** @return HasMany<MaintenanceSchedule, $this> */
    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return HasMany<CarBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(CarBlock::class);
    }

    /** @return HasMany<Fine, $this> */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    /** @return HasMany<OwnerInstallment, $this> */
    public function ownerInstallments(): HasMany
    {
        return $this->hasMany(OwnerInstallment::class);
    }

    public function registerMediaCollections(): void
    {
        // ADR-009: media lives on the private disk. A car photo identifies a vehicle
        // and its plate, so it is no more public than a contract scan.
        $this->addMediaCollection('gallery')
            ->useDisk('private');

        $this->addMediaCollection('damage')
            ->useDisk('private');
    }

    /**
     * A company-owned car has no third-party owner. The edit form hides `car_owner_id`
     * when ownership is not third-party, and a hidden Filament field is skipped during
     * dehydration — so without this the previous owner would survive the switch and the
     * car would read as company-owned while still pointing at a `car_owner`.
     */
    protected static function booted(): void
    {
        static::saving(function (self $car): void {
            if ($car->ownership_type === OwnershipType::CompanyOwned) {
                $car->car_owner_id = null;
            }
        });
    }
}
