<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Expense extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, HasLedgerPostings, SoftDeletes;

    protected $fillable = [
        'reference',
        'branch_id',
        'expense_category_id',
        'car_id',
        'vendor_id',
        'employee_id',
        'amount',
        'total_amount',
        'incurred_on',
        'description',
        'invoice_number',
        'status',
        'approved_by_id',
        'approved_at',
        'rejection_reason',
        'payment_method',
        'financial_account_id',
        'paid_at',
        'is_recurring',
        'recurrence_rule',
        'parent_expense_id',
        'next_occurrence_on',
        'transaction_id',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (! $expense->reference) {
                $expense->reference = 'EXP-'.strtoupper(Str::random(10));
            }
        });
    }

    public function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'incurred_on' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_recurring' => 'boolean',
            'next_occurrence_on' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function parentExpense(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_expense_id');
    }
}
