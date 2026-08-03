<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property int|null $branch_id
 */
class Activity extends SpatieActivity
{
    /**
     * Stamp the branch a change belongs to (docs/resource/38-activity-log.md gap 2).
     *
     * Spatie writes these rows itself, so BelongsToBranch's auto-fill never
     * runs on them. The subject's branch is authoritative — that is where the
     * change landed — with the acting user's branch as the fallback for
     * system-level events that have no subject (roles_updated,
     * password_reset). Rows with neither stay null and are simply invisible
     * to branch-pinned users, which is the correct default for a global
     * action.
     */
    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            if ($activity->branch_id !== null) {
                return;
            }

            $branchId = $activity->subject?->getAttribute('branch_id')
                ?? $activity->causer?->getAttribute('branch_id');

            $activity->branch_id = is_int($branchId) ? $branchId : null;
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
