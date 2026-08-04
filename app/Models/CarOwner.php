<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CarOwnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarOwner extends Model
{
    /** @use HasFactory<CarOwnerFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'type',
        'first_name',
        'last_name',
        'company_name',
        'trade_register',
        'national_id',
        'phone',
        'whatsapp',
        'email',
        'address',
        'wilaya',
        'bank_name',
        'bank_rib',
        'ccp_account',
        'baridimob_number',
        'notes',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Car, $this> */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    /** @return HasMany<CarOwnershipAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(CarOwnershipAgreement::class);
    }

    /** @return HasMany<OwnerInstallment, $this> */
    public function ownerInstallments(): HasMany
    {
        return $this->hasMany(OwnerInstallment::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
