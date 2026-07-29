<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the signed-in user's stored language.
 *
 * Must run after the session has started, or Auth::user() is null and this is a no-op —
 * hence the panel's middleware list and the `web` group, never the global stack.
 */
class SetLocaleFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Auth::user()?->locale;

        if ($locale instanceof Locale) {
            App::setLocale($locale->value);
        }

        return $next($request);
    }
}
