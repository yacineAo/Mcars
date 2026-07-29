<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;

/**
 * Session-scoped branch context.
 *
 * Populated by ResolveBranchContext middleware on every request. Provides the
 * current branch selection to BranchScope and BelongsToBranch auto-fill.
 *
 * Null activeBranch = "All Branches" (company-wide view), only available to
 * users with branches.view_all permission.
 */
class BranchContext
{
    private ?int $activeBranchId = null;

    private bool $isResolved = false;

    /**
     * Resolve from a branch id. Validates that the id exists.
     * Returns true if the branch was found and set, false otherwise.
     */
    public function resolve(?int $branchId): bool
    {
        $this->isResolved = true;
        $this->activeBranchId = null;

        if ($branchId === null) {
            return true;
        }

        $exists = Branch::query()->where('id', $branchId)->exists();

        if ($exists) {
            $this->activeBranchId = $branchId;
        }

        return $exists;
    }

    /**
     * Whether the middleware has populated this context in the current request.
     */
    public function isResolved(): bool
    {
        return $this->isResolved;
    }

    /**
     * The currently selected branch id, or null for company-wide.
     */
    public function activeBranchId(): ?int
    {
        return $this->activeBranchId;
    }

    /**
     * Whether the user has selected "All Branches" (company-wide).
     */
    public function isGlobal(): bool
    {
        return $this->isResolved && $this->activeBranchId === null;
    }

    /**
     * Convenience: the active Branch model, or null.
     */
    public function activeBranch(): ?Branch
    {
        if ($this->activeBranchId === null) {
            return null;
        }

        return Branch::find($this->activeBranchId);
    }

    /**
     * Reset the context (used in tests or when leaving scope).
     */
    public function reset(): void
    {
        $this->activeBranchId = null;
        $this->isResolved = false;
    }
}
