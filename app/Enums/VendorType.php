<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum VendorType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case Garage = 'garage';
    case Insurance = 'insurance';
    case Parts = 'parts';
    case FuelStation = 'fuel_station';
    case Towing = 'towing';
    case Other = 'other';

    public function getIcon(): string
    {
        return match ($this) {
            self::Garage => 'heroicon-o-wrench',
            self::Insurance => 'heroicon-o-shield-check',
            self::Parts => 'heroicon-o-cog',
            self::FuelStation => 'heroicon-o-fire',
            self::Towing => 'heroicon-o-truck',
            self::Other => 'heroicon-o-building-storefront',
        };
    }
}
