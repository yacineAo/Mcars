<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeductionReason;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DeductionReason $reason
 * @property string $amount
 */
class DepositDeduction extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'deposit_id', 'reason', 'amount', 'description',
        'condition_report_id', 'fine_id', 'created_by_id',
    ];

    /**
     * `reason` is cast because callers set it both ways — the Filament select
     * hands over a string, the services hand over a DeductionReason — and with
     * no cast the attribute kept whatever it was given. Code downstream then had
     * to test the type at runtime to find the value it needed, which is a check
     * that silently starts returning the wrong branch the day a caller changes.
     * The cast makes the model the single answer to "what type is this?".
     */
    protected function casts(): array
    {
        return [
            'reason' => DeductionReason::class,
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Deposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    /** @return BelongsTo<ConditionReport, $this> */
    public function conditionReport(): BelongsTo
    {
        return $this->belongsTo(ConditionReport::class);
    }

    /** @return BelongsTo<Fine, $this> */
    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }
}
