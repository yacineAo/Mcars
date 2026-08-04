<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that restricts queries to the currently-selected branch.
 *
 * Controlled by the `branches.enabled` feature flag. When disabled — the
 * default in Phase 10 deployment — this scope is a no-op and the system
 * behaves exactly as before.
 *
 * ## Where the scope does NOT apply
 *
 * - **Queued jobs:** No session exists. Jobs must carry an explicit branch_id
 *   in their payload.
 * - **Console commands / schedulers:** Nightly installments, alerts, and
 *   integrity checks run company-wide.
 * - **Tests:** Always create records with an explicit branch.
 *
 * Resolution rule (matches User::accessibleBranchIds()):
 * 1. User has `branches.view_all` permission → scope is a no-op (all data).
 * 2. BranchContext has an active branch → scope to that branch.
 * 3. No context → scope is a no-op (safe fallback for queue/console).
 *
 * @see docs/08-multi-branch-retrofit.md §3
 *
 * @implements Scope<Model>
 */
class BranchScope implements Scope
{
    public function __construct(
        private readonly BranchContext $context,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (! config('branches.enabled', false)) {
            return;
        }

        if (! $this->context->isResolved()) {
            return;
        }

        $branchId = $this->context->activeBranchId();

        if ($branchId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('branch_id'), $branchId);
    }
}
