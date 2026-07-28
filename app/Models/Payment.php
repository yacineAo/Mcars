<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToBranch, HasAuditColumns, HasLedgerPostings;

    protected $fillable = [
        'reference', 'branch_id', 'direction',
        'payable_type', 'payable_id',
        'customer_id', 'car_owner_id', 'employee_id',
        'method', 'amount', 'currency', 'paid_at',
        'financial_account_id', 'status',
        'external_reference', 'cheque_due_date',
        'received_by_id', 'cash_session_id', 'notes',
    ];

    /**
     * This model previously declared no casts at all, so `paid_at` came back as a
     * raw string and PaymentPoster fatally called ->format() on it — every payment
     * recorded through the UI failed to post. The unit tests missed it because they
     * pass a freshly-built model whose attribute is still a Carbon instance.
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'cheque_due_date' => 'date',
        ];
    }

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

    /**
     * Which cash box, bank or wallet the money moved through.
     *
     * PaymentResource already selects on this relation; its absence broke the
     * whole create-payment page.
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
