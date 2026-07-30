<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property AgreementStatus $status
 * @property AgreementModel $model
 * @property CarbonInterface $start_date
 * @property CarbonInterface|null $end_date
 * @property CarbonInterface|null $first_due_date
 */
class CarOwnershipAgreement extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'car_id',
        'car_owner_id',
        'branch_id',
        'model',
        'monthly_rent_amount',
        'share_percentage',
        'start_date',
        'end_date',
        'payment_day_of_month',
        'installments_count',
        'first_due_date',
        'grace_days',
        'status',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'model' => AgreementModel::class,
            'status' => AgreementStatus::class,
            'monthly_rent_amount' => 'decimal:2',
            'share_percentage' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'first_due_date' => 'date',
            'payment_day_of_month' => 'integer',
            'installments_count' => 'integer',
            'grace_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Car> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<CarOwner> */
    public function carOwner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class);
    }

    /** @return HasMany<OwnerInstallment> */
    public function ownerInstallments(): HasMany
    {
        return $this->hasMany(OwnerInstallment::class, 'car_ownership_agreement_id');
    }
}
