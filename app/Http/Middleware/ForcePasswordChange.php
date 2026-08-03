<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user whose password was reset by a manager (UserService::resetPassword) is
 * forced to change it before doing anything else in the panel: every request that
 * is not the profile page or the logout action is bounced to the profile.
 *
 * The check lives here rather than in the login flow so it covers existing
 * sessions too — a reset takes effect for the person who is already signed in,
 * not just at their next sign-in. Livewire update requests never pass through
 * panel middleware, so saving the profile form is unaffected by the redirect.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->must_change_password) {
            $isAllowed = $request->routeIs('filament.admin.auth.logout', 'filament.admin.auth.profile');

            if (! $isAllowed) {
                return redirect()->to(Filament::getProfileUrl());
            }
        }

        return $next($request);
    }
}
