<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum Locale: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Arabic = 'ar';
    case French = 'fr';
    case English = 'en';

    public function getColor(): string
    {
        return match ($this) {
            self::Arabic => 'danger',
            self::French => 'info',
            self::English => 'success',
        };
    }

    public function getIcon(): string
    {
        return 'heroicon-o-language';
    }

    /**
     * Text direction for this locale.
     *
     * Filament derives the panel's `dir` from its own `filament-panels::layout.direction`
     * translation, so the admin UI does not need this. Generated documents do: a PDF is
     * rendered on the queue, where there is no session and no request locale to infer
     * from — ExportJob passes this through explicitly.
     */
    public function direction(): string
    {
        return match ($this) {
            self::Arabic => 'rtl',
            default => 'ltr',
        };
    }
}
