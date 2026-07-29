<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

class Transaction extends Model
{
    protected $fillable = [
        'uuid',
        'reference',
        'branch_id',
        'occurred_on',
        'posted_at',
        'debit_account_id',
        'credit_account_id',
        'amount',
        'currency',
        'exchange_rate',
        'type',
        'payment_method',
        'description',
        'car_id',
        'booking_id',
        'contract_id',
        'customer_id',
        'car_owner_id',
        'employee_id',
        'expense_category_id',
        'source_type',
        'source_id',
        'created_by_id',
        'cash_session_id',
        'reverses_transaction_id',
        'reversed_by_transaction_id',
        'is_reversal',
        'meta',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            $transaction->uuid ??= (string) Str::uuid();
            $transaction->posted_at ??= now();
        });

        static::updating(function (Transaction $transaction): never {
            $transaction->logRejectedMutation('update');
            throw new RuntimeException(
                'Transactions are append-only. Use AccountingService::reverse() to correct mistakes.',
            );
        });

        static::deleting(function (Transaction $transaction): never {
            $transaction->logRejectedMutation('delete');
            throw new RuntimeException('Transactions are append-only. Deletion is not permitted.');
        });
    }

    protected function logRejectedMutation(string $operation): void
    {
        activity('ledger')
            ->causedBy(Auth::user())
            ->performedOn($this)
            ->withProperties([
                'operation' => $operation,
                'transaction_id' => $this->id,
                'transaction_uuid' => $this->uuid,
                'reference' => $this->reference,
                'attempted_at' => now()->toIso8601String(),
                'ip' => optional(request())->ip(),
                'user_agent' => optional(request())->userAgent(),
            ])
            ->log("rejected_ledger_mutation:{$operation}");
    }

    public function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'posted_at' => 'datetime',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'type' => TransactionType::class,
            'payment_method' => PaymentMethod::class,
            'is_reversal' => 'boolean',
            'meta' => 'json',
        ];
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function carOwner(): BelongsTo
    {
        return $this->belongsTo(CarOwner::class, 'car_owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function reversesTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversedByTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_transaction_id');
    }
}
