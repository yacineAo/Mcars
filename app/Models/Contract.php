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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string|null $uuid
 * @property string|null $contract_number
 * @property int|null $branch_id
 * @property int|null $booking_id
 * @property int|null $car_id
 * @property int|null $customer_id
 * @property int|null $contract_template_id
 * @property ContractStatus $status
 * @property array<string, mixed>|null $content_snapshot
 * @property string|null $terms_version
 * @property InsuranceType|null $insurance_type
 * @property string|null $franchise_amount
 * @property Carbon|null $generated_at
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 * @property string|null $document_hash
 * @property Carbon|null $sent_at
 * @property string|null $sent_channel
 * @property string|null $sent_to
 * @property Carbon|null $signed_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_id
 * @property string|null $closing_notes
 * @property bool|null $has_damages
 * @property int|null $parent_contract_id
 * @property-read Booking|null $booking
 * @property-read Car|null $car
 * @property-read Customer|null $customer
 * @property-read ContractTemplate|null $template
 */
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

    /** @return MorphMany<PaymentSchedule, $this> */
    public function paymentSchedules(): MorphMany
    {
        return $this->morphMany(PaymentSchedule::class, 'schedulable');
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

    /**
     * The booking's condition reports — the records that open and close the rental.
     *
     * @return HasManyThrough<ConditionReport>
     */
    public function conditionReports(): HasManyThrough
    {
        return $this->hasManyThrough(
            ConditionReport::class,
            Booking::class,
            'id',
            'booking_id',
            'booking_id',
            'id',
        );
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function scopeActive($query): void
    {
        $query->whereIn('status', [ContractStatus::Active, ContractStatus::Signed]);
    }

    /**
     * Once signed, the terms it embeds are immutable — ADR-005 freezes the document.
     */
    public function isLocked(): bool
    {
        return ! $this->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature);
    }

    /**
     * Text direction of the embedded document, from the locale the snapshot was
     * rendered in — Arabic snapshots render right-to-left even in an LTR panel.
     */
    public function direction(): string
    {
        return ($this->content_snapshot['locale'] ?? 'fr') === 'ar' ? 'rtl' : 'ltr';
    }
}
