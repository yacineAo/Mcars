<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every operational and financial model.
 *
 * In Phase 0-9 this only wires the relation and auto-fills branch_id on create.
 * The global BranchScope that actually restricts visibility is added in Phase 10
 * (ADV-06) — see docs/08-multi-branch-retrofit.md §3 for why the scope must NOT
 * apply to queued jobs, console commands, or the owner/client portals.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::creating(function ($model): void {
            if ($model->branch_id !== null) {
                return;
            }

            $model->branch_id = static::resolveBranchId();
        });
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, Branch|int|null $branch): Builder
    {
        if ($branch === null) {
            return $query;
        }

        return $query->where(
            $this->qualifyColumn('branch_id'),
            $branch instanceof Branch ? $branch->getKey() : $branch,
        );
    }

    /**
     * Auto-fill source, in order: the authenticated user's home branch, then the
     * default branch.
     *
     * Phase 10 inserts the session branch context ahead of both. Deliberately
     * returns null rather than guessing when neither is available — a wrong
     * branch on a ledger row is worse than a null the NOT NULL constraint
     * catches immediately.
     */
    protected static function resolveBranchId(): ?int
    {
        $user = auth()->user();

        if ($user !== null && isset($user->branch_id) && $user->branch_id !== null) { // @phpstan-ignore notIdentical.alwaysTrue
            return (int) $user->branch_id;
        }

        return Branch::defaultId();
    }
}
