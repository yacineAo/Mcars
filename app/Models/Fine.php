<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fine extends Model
{
    use BelongsToBranch, HasLedgerPostings, LogsActivity;

    protected $fillable = [
        'reference', 'branch_id', 'car_id',
        'booking_id', 'contract_id', 'customer_id',
        'type', 'authority', 'notice_number',
        'violation_at', 'location', 'received_at', 'due_date',
        'amount', 'late_penalty_amount', 'total_amount',
        'liability', 'liability_determined_by_id', 'liability_determined_at', 'liability_note',
        'status', 'paid_at', 'payment_id', 'notes',
    ];

    /**
     * This model had no casts, so `status`, `liability` and every money and date
     * field came back as raw strings — the same class of defect that made payments
     * fail to post.
     */
    protected function casts(): array
    {
        return [
            'type' => FineType::class,
            'status' => FineStatus::class,
            'liability' => FineLiability::class,
            'amount' => 'decimal:2',
            'late_penalty_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'violation_at' => 'datetime',
            'received_at' => 'datetime',
            'due_date' => 'date',
            'liability_determined_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function liabilityDeterminedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liability_determined_by_id');
    }
}
