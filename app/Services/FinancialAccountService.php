<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way `is_default_for_cash` changes. Mirrors
 * BranchService::makeDefault() — exactly one *live* account ever holds the flag,
 * cleared across trashed rows too so a soft-deleted default cannot collide with
 * its replacement. The DB carries the same invariant via the partial unique index
 * `financial_accounts_single_default_for_cash`; this service is what keeps app-level
 * writes from ever reaching it with two rows set.
 */
final class FinancialAccountService
{
    public function makeDefaultForCash(FinancialAccount $account, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('Only staff can change the default cash account.'));
        }

        if ($account->is_default_for_cash) {
            return;
        }

        if (! $account->is_active) {
            throw new DomainException(__('An inactive account cannot be made the default for cash.'));
        }

        DB::transaction(function () use ($account): void {
            FinancialAccount::withTrashed()
                ->where('is_default_for_cash', true)
                ->update(['is_default_for_cash' => false]);

            $account->update(['is_default_for_cash' => true]);
        });
    }
}
