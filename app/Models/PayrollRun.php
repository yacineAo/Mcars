<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property PayrollStatus $status
 * @property int|null $branch_id
 */
class PayrollRun extends Model
{
    use BelongsToBranch, HasLedgerPostings, LogsActivity;

    protected $fillable = [
        'branch_id', 'period_month', 'status',
        'approved_by_id', 'approved_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            // Was missing, so status came back as a raw string and every
            // `=== PayrollStatus::X` comparison silently evaluated false.
            'status' => PayrollStatus::class,
            'period_month' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
