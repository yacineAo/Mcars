<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fine extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'reference', 'branch_id', 'car_id',
        'booking_id', 'contract_id', 'customer_id',
        'type', 'authority', 'notice_number',
        'violation_at', 'location', 'received_at', 'due_date',
        'amount', 'late_penalty_amount', 'total_amount',
        'liability', 'liability_determined_by_id', 'liability_determined_at', 'liability_note',
        'status', 'paid_at', 'payment_id', 'notes',
    ];

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
