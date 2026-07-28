<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerInstallment extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'car_ownership_agreement_id', 'car_owner_id', 'car_id', 'branch_id',
        'sequence_number', 'total_installments',
        'period_month', 'due_date', 'amount_due',
        'status', 'accrual_transaction_id',
        'waived_reason', 'notes',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CarOwnershipAgreement::class, 'car_ownership_agreement_id');
    }

    public function carOwner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function accrualTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'accrual_transaction_id');
    }
}
