<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
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
 * @property int|null $contract_id
 * @property PaymentMethod $method
 * @property CarbonInterface $held_at
 * @property CarbonInterface|null $settled_at
 * @property-read Booking|null $booking
 * @property-read Customer|null $customer
 * @property-read User|null $settledBy
 * @property-read string|null $deductions_sum_amount
 * @property-read string|null $refunds_sum_amount
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

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return HasMany<DepositDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(DepositDeduction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_id');
    }
}
