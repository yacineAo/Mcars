<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Locale;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class LocaleSwitcher extends Component
{
    public function render(): View
    {
        return view('livewire.locale-switcher', [
            'locales' => Locale::cases(),
            // Read through the cast rather than getRawOriginal(): the raw accessor
            // returns the value as first loaded, so it goes stale after an update in the
            // same request, and it bypasses the enum the model now declares.
            //
            // Null only for a guest, who never sees this — the switcher renders inside
            // the authenticated panel — and then nothing is highlighted, which is right.
            'current' => Auth::user()?->locale,
        ]);
    }
}
