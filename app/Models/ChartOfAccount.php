<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'name_fr',
        'type',
        'parent_id',
        'normal_balance',
        'is_cash_equivalent',
        'is_postable',
        'is_system',
        'branch_id',
        'description',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_cash_equivalent' => 'boolean',
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isBalanceSheetAccount(): bool
    {
        return $this->type->is(AccountType::Asset, AccountType::Liability, AccountType::Equity);
    }

    public function isNominalAccount(): bool
    {
        return $this->type->is(AccountType::Revenue, AccountType::Expense);
    }
}
