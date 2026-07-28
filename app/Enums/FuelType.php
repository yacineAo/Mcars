<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FuelType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Gpl = 'gpl';
    case Hybrid = 'hybrid';
    case Electric = 'electric';

    public function getIcon(): string
    {
        return match ($this) {
            self::Petrol => 'heroicon-o-fire',
            self::Diesel => 'heroicon-o-beaker',
            self::Gpl => 'heroicon-o-circle-stack',
            self::Hybrid => 'heroicon-o-arrows-right-left',
            self::Electric => 'heroicon-o-bolt',
        };
    }
}
