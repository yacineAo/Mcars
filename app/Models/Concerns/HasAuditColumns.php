<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stamps created_by_id / updated_by_id automatically.
 *
 * Distinct from Spatie Activitylog (Phase 10, ADV-03): that records the history
 * of changes, this answers "who owns this row right now" cheaply enough to put
 * in a table column. REQ-09 needs the operator on every cash movement without a
 * join to the activity log.
 *
 * @phpstan-require-extends Model
 */
trait HasAuditColumns
{
    public static function bootHasAuditColumns(): void
    {
        static::creating(function ($model): void {
            $userId = auth()->id();

            if ($userId === null) {
                return;
            }

            $model->created_by_id ??= $userId;
            $model->updated_by_id ??= $userId;
        });

        static::updating(function ($model): void {
            if ($userId = auth()->id()) {
                $model->updated_by_id = $userId;
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
