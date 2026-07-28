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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Car extends Model implements HasMedia
{
    use BelongsToBranch, HasAuditColumns, HasFactory, InteractsWithMedia, SoftDeletes;

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

    /** @return BelongsTo<CarCategory> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class, 'car_category_id');
    }

    /** @return BelongsTo<CarOwner> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class, 'car_owner_id');
    }

    /** @return HasMany<CarOwnershipAgreement> */
    public function agreements(): HasMany
    {
        return $this->hasMany(CarOwnershipAgreement::class);
    }

    /** @return HasMany<CarDocument> */
    public function documents(): HasMany
    {
        return $this->hasMany(CarDocument::class);
    }

    /** @return HasMany<MaintenanceLog> */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    /** @return HasMany<MaintenanceSchedule> */
    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('damage');
    }
}
