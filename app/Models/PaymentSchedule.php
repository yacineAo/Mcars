<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentSchedule extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'schedulable_type', 'schedulable_id',
        'customer_id', 'branch_id',
        'sequence', 'due_date', 'amount',
        'status', 'notes',
    ];

    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
