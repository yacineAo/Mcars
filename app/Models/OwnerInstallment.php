<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLedgerPostings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerInstallment extends Model
{
    use BelongsToBranch, HasLedgerPostings;

    protected $fillable = [
        'car_ownership_agreement_id', 'car_owner_id', 'car_id', 'branch_id',
        'sequence_number', 'total_installments',
        'period_month', 'due_date', 'amount_due',
        'status', 'accrual_transaction_id',
        'waived_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallmentStatus::class,
            'period_month' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'amount_due' => 'decimal:2',
        ];
    }

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
