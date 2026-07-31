<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ExpenseCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'slug',
        'parent_id',
        'ledger_account_id',
        'is_car_related',
        'is_recurring_default',
        'sort_order',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'is_car_related' => 'boolean',
            'is_recurring_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ledger_account_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }

    public function hasExpenses(): bool
    {
        // Soft-deleted expenses keep their ledger postings, so they must keep the category frozen.
        return $this->expenses()->exists();
    }

    public static function slugFromName(string $name): string
    {
        return (string) Str::of($name)->slug('-');
    }
}
