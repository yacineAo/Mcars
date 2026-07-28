<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialAccount extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, SoftDeletes;

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

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ledger_account_id');
    }
}
