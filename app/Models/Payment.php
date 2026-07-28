<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToBranch, HasAuditColumns;

    protected $fillable = [
        'reference', 'branch_id', 'direction',
        'payable_type', 'payable_id',
        'customer_id', 'car_owner_id', 'employee_id',
        'method', 'amount', 'currency', 'paid_at',
        'financial_account_id', 'status',
        'external_reference', 'cheque_due_date',
        'received_by_id', 'cash_session_id', 'notes',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function carOwner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
