<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel middleware that hydrates BranchContext from the session.
 *
 * Resolves on every request and re-validates against the user's accessible
 * branches, so a revoked grant takes effect immediately (not at session
 * expiry).
 */
class ResolveBranchContext
{
    public function __construct(
        private readonly BranchContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            $this->context->reset();

            return $next($request);
        }

        $branchId = $request->session()->get('branch_context_active_id');

        if ($branchId === null) {
            $this->context->resolve(null);

            return $next($request);
        }

        $accessibleIds = $user->accessibleBranchIds();

        if (in_array((int) $branchId, $accessibleIds, true)) {
            $this->context->resolve((int) $branchId);
        } else {
            $this->context->resolve(null);
        }

        return $next($request);
    }
}
