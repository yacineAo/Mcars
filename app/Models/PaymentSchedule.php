<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property InstallmentStatus $status
 * @property string|null $schedulable_type
 * @property int|null $schedulable_id
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property int $sequence
 * @property Carbon $due_date
 * @property string $amount
 * @property Carbon|null $reminder_sent_at
 * @property string|null $notes
 * @property string|null $waived_reason
 * @property Carbon|null $waived_at
 * @property int|null $waived_by_id
 */
class PaymentSchedule extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'schedulable_type', 'schedulable_id',
        'customer_id', 'branch_id',
        'sequence', 'due_date', 'amount',
        'status', 'notes',
        'waived_reason', 'waived_at', 'waived_by_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'status' => InstallmentStatus::class,
            'reminder_sent_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PaymentScheduleAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentScheduleAllocation::class);
    }
}
