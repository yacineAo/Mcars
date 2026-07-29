<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class LocaleController
{
    /**
     * Persist the signed-in user's language.
     *
     * POST rather than GET: this writes to the database, and a GET that mutates state is
     * both CSRF-forgeable from any page and open to a browser link-prefetcher flipping a
     * user's language without them clicking anything.
     */
    public function switch(string $locale): RedirectResponse
    {
        $localeEnum = Locale::tryFrom($locale);

        if ($localeEnum === null) {
            abort(404);
        }

        Auth::user()?->update(['locale' => $localeEnum]);

        return back();
    }
}
