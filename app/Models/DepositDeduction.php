<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositDeduction extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'deposit_id', 'reason', 'amount', 'description',
        'condition_report_id', 'fine_id', 'created_by_id',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function conditionReport(): BelongsTo
    {
        return $this->belongsTo(ConditionReport::class);
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }
}
