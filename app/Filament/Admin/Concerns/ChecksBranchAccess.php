<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The two halves of branch scoping on a resource, kept in one place so they
 * cannot drift apart.
 *
 * They were written separately three times (Payment, Expense, CashSession) and
 * had already diverged: the list query asked `accessibleBranchIds()`, which
 * honours the `branch_user` pivot, while the record check compared
 * `users.branch_id` directly. A user granted a second branch through the pivot
 * therefore saw rows in the table that returned 403 when opened.
 * `accessibleBranchIds()` is the one definition of "which branches can this user
 * reach"; both halves now ask it.
 */
trait ChecksBranchAccess
{
    /**
     * Restrict a query to the branches the current user may reach.
     *
     * Fails closed. An empty accessible set means "no branch access configured"
     * (User::accessibleBranchIds() reports it), and skipping the constraint there
     * would widen the query to every branch — the exact opposite of what an
     * unconfigured user should see. Rows with a null `branch_id` are excluded for
     * anyone without `branches.view_all`, which is what `whereIn` already does and
     * what keeps this consistent with the record check below.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function pinToAccessibleBranches(Builder $query, string $column = 'branch_id'): Builder
    {
        $user = Auth::user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('branches.view_all')) {
            return $query;
        }

        $ids = $user->accessibleBranchIds();

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $ids);
    }

    /**
     * Whether the current user may reach a single record's branch.
     *
     * The record-level mirror of the query above: a row the list can show is a row
     * the view and edit pages must open, and vice versa.
     */
    public static function userCanReachBranch(?int $branchId): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->can('branches.view_all')) {
            return true;
        }

        return $branchId !== null
            && in_array($branchId, $user->accessibleBranchIds(), true);
    }
}
