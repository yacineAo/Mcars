<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the branch and period every dashboard widget reports on.
 *
 * The branch filter is a convenience for people who may already see every branch —
 * never a way to widen your own scope. A user tied to a branch is pinned to it here
 * regardless of what the filter says, because the filter value arrives from the
 * browser and Livewire will happily accept whatever it is sent.
 */
trait ScopesDashboardReports
{
    use InteractsWithPageFilters;

    /**
     * Whether the current user may see revenue, profit, cash flow and receivables.
     */
    public static function canViewFinancials(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('reports.view_financials');
    }

    /**
     * Whether the current user may report across branches.
     */
    public static function canViewAllBranches(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('branches.view_all');
    }

    /**
     * The branch to report on: null means the global roll-up across all branches.
     */
    protected function reportBranchId(): ?int
    {
        $user = Auth::user();

        // Pinned to your own branch unless you are explicitly allowed to see others.
        if (! static::canViewAllBranches()) {
            return $user?->branch_id;
        }

        $filtered = $this->pageFilters['branch_id'] ?? null;

        return filled($filtered) ? (int) $filtered : null;
    }

    /**
     * The reporting period, defaulting to the current month.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function reportPeriod(): array
    {
        $today = CarbonImmutable::today();

        $from = filled($this->pageFilters['from'] ?? null)
            ? CarbonImmutable::parse($this->pageFilters['from'])->startOfDay()
            : $today->startOfMonth();

        $to = filled($this->pageFilters['to'] ?? null)
            ? CarbonImmutable::parse($this->pageFilters['to'])->endOfDay()
            : $today->endOfMonth();

        // A reversed range would silently report zero everywhere.
        return $from->greaterThan($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }
}
