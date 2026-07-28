<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TransmissionType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case Manual = 'manual';
    case Automatic = 'automatic';

    public function getIcon(): string
    {
        return match ($this) {
            self::Manual => 'heroicon-o-cog',
            self::Automatic => 'heroicon-o-cog-6-tooth',
        };
    }
}
