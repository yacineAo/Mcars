<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\InsuranceType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contract extends Model
{
    use BelongsToBranch, LogsActivity;

    protected $fillable = [
        'uuid',
        'contract_number',
        'branch_id',
        'booking_id',
        'car_id',
        'customer_id',
        'contract_template_id',
        'status',
        'content_snapshot',
        'terms_version',
        'insurance_type',
        'franchise_amount',
        'generated_at',
        'pdf_disk',
        'pdf_path',
        'document_hash',
        'sent_at',
        'sent_channel',
        'sent_to',
        'signed_at',
        'closed_at',
        'closed_by_id',
        'closing_notes',
        'has_damages',
        'parent_contract_id',
    ];

    protected function casts(): array
    {
        return [
            'uuid' => 'string',
            'status' => ContractStatus::class,
            'insurance_type' => InsuranceType::class,
            'content_snapshot' => 'array',
            'franchise_amount' => 'decimal:2',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'closed_at' => 'datetime',
            'has_damages' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            $contract->uuid ??= (string) Str::uuid();
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function parentContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_contract_id');
    }

    public function childContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_contract_id');
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function scopeActive($query): void
    {
        $query->whereIn('status', [ContractStatus::Active, ContractStatus::Signed]);
    }
}
