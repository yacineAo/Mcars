<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `status` is declared here because static analysis reads column types from the schema,
 * not from casts() — without it `$session->status` looks like a string and the strict
 * `=== CashSessionStatus::Open` guards on the close actions read as dead code.
 *
 * @property CashSessionStatus $status
 */
class CashSession extends Model
{
    use BelongsToBranch, HasFactory, HasLedgerPostings, LogsActivity;

    protected $fillable = [
        'branch_id',
        'financial_account_id',
        'opened_by_id',
        'opened_at',
        'opening_float',
        'closed_by_id',
        'closed_at',
        'counted_amount',
        'status',
        'reconciled_by_id',
        'reconciled_at',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_float' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
