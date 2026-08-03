<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way the default / active / deleted state of a branch
 * changes. The branch *form* still writes the address fields directly; this
 * service owns the three decisions that must stay consistent regardless of how
 * they are triggered:
 *
 *   - makeDefault(): exactly one *live* branch is the default, ever. The flag
 *     is cleared across trashed rows too — a soft-deleted default branch must
 *     stop claiming it, otherwise the replacement collides with the ghost row
 *     (the pre-Round-35 unique index) or the state quietly lies.
 *   - setActive(): the default branch cannot be switched off.
 *   - delete(): refused for the default branch and for any branch that still
 *     has rows pointing at it — see dependentColumnCounts().
 */
final class BranchService
{
    /**
     * Promotes a branch to default, demoting whatever held the flag before.
     * Refuses an inactive branch: the default is the office every null
     * branch_id resolves to, it must be reachable.
     */
    public function makeDefault(Branch $branch, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('branches.errors.action_requires_staff'));
        }

        if ($branch->is_default) {
            return;
        }

        if (! $branch->is_active) {
            throw new DomainException(__('branches.errors.cannot_default_inactive'));
        }

        DB::transaction(function () use ($branch): void {
            Branch::withTrashed()
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $branch->update(['is_default' => true]);
        });

        activity()
            ->causedBy($actor)
            ->performedOn($branch)
            ->event('made_default')
            ->log(__('branches.activity.made_default'));
    }

    /**
     * Deactivate (park a closed site) or reactivate. `is_active` flips are
     * already audited by the model's activity trait — including the causer —
     * so no extra activity row is written here, mirroring UserService.
     */
    public function setActive(Branch $branch, bool $active, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('branches.errors.action_requires_staff'));
        }

        if (! $active && $branch->is_default) {
            throw new DomainException(__('branches.errors.cannot_deactivate_default'));
        }

        $branch->update(['is_active' => $active]);
    }

    /**
     * Refuses to simulate-remove a branch until it is safe:
     *  - never the default branch — the default flag has to live somewhere;
     *  - never while any row still points at it. Every branch_id foreign key is
     *    nullOnDelete, the append-only ledger included, so deleting the row would
     *    quietly un-attribute every transaction an office ever recorded. That
     *    history is not throwaway: a branch is deletable only once its rows have
     *    been rehomed or wound down (Phase 10 tooling).
     *
     * The ids of the rows are intact; this is a soft delete.
     */
    public function delete(Branch $branch, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('branches.errors.action_requires_staff'));
        }

        if ($branch->is_default) {
            throw new DomainException(__('branches.errors.cannot_delete_default'));
        }

        foreach ($this->dependentColumnCounts($branch) as $count) {
            if ($count > 0) {
                throw new DomainException(__('branches.errors.has_dependent_rows'));
            }
        }

        $branch->delete();
    }

    /**
     * Counts, per table, the rows still pointing at the branch. The scan is
     * schema-driven so a branch_id column added by a later phase is picked up
     * without touching this method. Normalise away the soft-deleted users or a
     * pivot with no rows.
     *
     * @return array<string, int> table name => rows pointing at the branch
     */
    private function dependentColumnCounts(Branch $branch): array
    {
        $tables = DB::select(
            "SELECT table_name
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND column_name = 'branch_id'",
        );

        $counts = [];

        foreach ($tables as $row) {
            $table = $row->table_name;

            if (! is_string($table) || $table === 'branches') {
                continue;
            }

            $counts[$table] = DB::table($table)->where('branch_id', $branch->getKey())->count();
        }

        return $counts;
    }
}
