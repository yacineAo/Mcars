<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DepositStatus $status
 * @property string $amount
 * @property int $booking_id
 * @property int $customer_id
 * @property int|null $branch_id
 */
class Deposit extends Model
{
    use BelongsToBranch, HasAuditColumns, HasLedgerPostings, LogsActivity;

    protected $fillable = [
        'booking_id', 'contract_id', 'customer_id', 'branch_id',
        'amount', 'method', 'financial_account_id',
        'held_at', 'payment_id', 'status',
        'settled_at', 'settled_by_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DepositStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'held_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
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

    public function deductions(): HasMany
    {
        return $this->hasMany(DepositDeduction::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_id');
    }
}
