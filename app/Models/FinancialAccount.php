<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'ledger_account_id',
        'name',
        'type',
        'account_number',
        'rib',
        'holder_name',
        'currency',
        'opening_balance',
        'opened_on',
        'allowed_payment_methods',
        'is_default_for_cash',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'currency' => 'string',
            'opening_balance' => 'decimal:2',
            'opened_on' => 'date',
            'allowed_payment_methods' => 'json',
            'is_default_for_cash' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ledger_account_id');
    }

    /** @return HasMany<CashSession, $this> */
    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function hasPostings(): bool
    {
        return Transaction::query()
            ->where('debit_account_id', $this->ledger_account_id)
            ->orWhere('credit_account_id', $this->ledger_account_id)
            ->exists();
    }

    /** @param Builder<FinancialAccount> $query
     *  @return Builder<FinancialAccount> */
    public function scopeWithCurrentBalance(Builder $query): Builder
    {
        return $query->addSelect('financial_accounts.*', DB::raw(
            '(SELECT COALESCE(SUM(CASE WHEN t.debit_account_id = financial_accounts.ledger_account_id THEN t.amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN t.credit_account_id = financial_accounts.ledger_account_id THEN t.amount ELSE 0 END), 0) FROM transactions t WHERE t.debit_account_id = financial_accounts.ledger_account_id OR t.credit_account_id = financial_accounts.ledger_account_id) AS current_balance',
        ));
    }
}
