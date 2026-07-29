<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Branch;
use App\Services\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Topbar branch switcher.
 *
 * Shows the currently-selected branch and allows switching. Mounted via the
 * `panels::global-search.after` render hook when multi-branch is enabled.
 */
class BranchSwitcher extends Component
{
    public ?int $activeBranchId = null;

    public function mount(): void
    {
        $this->activeBranchId = session('branch_context_active_id');
    }

    public function switch(?int $branchId): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $accessibleIds = $user->accessibleBranchIds();

        if ($branchId === null) {
            session(['branch_context_active_id' => null]);
            $this->activeBranchId = null;
            app(BranchContext::class)->resolve(null);
            $this->dispatch('branch-changed');

            return;
        }

        if (! in_array($branchId, $accessibleIds, true)) {
            return;
        }

        session(['branch_context_active_id' => $branchId]);
        $this->activeBranchId = $branchId;
        app(BranchContext::class)->resolve($branchId);
        $this->dispatch('branch-changed');
    }

    public function render(): View
    {
        $user = Auth::user();

        $accessibleBranches = $user !== null
            ? Branch::query()
                ->whereIn('id', $user->accessibleBranchIds())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
            : collect();

        $currentBranch = $this->activeBranchId !== null
            ? $accessibleBranches->firstWhere('id', $this->activeBranchId)
            : null;

        $canViewAll = $user !== null && $user->can('branches.view_all');

        return view('livewire.branch-switcher', [
            'accessibleBranches' => $accessibleBranches,
            'currentBranch' => $currentBranch,
            'canViewAll' => $canViewAll,
        ]);
    }
}
