<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DeductionReason: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Damage = 'damage';
    case Fuel = 'fuel';
    case LateReturn = 'late_return';
    case Cleaning = 'cleaning';
    case TrafficFine = 'traffic_fine';
    case Other = 'other';

    public function getColor(): string
    {
        return match ($this) {
            self::Damage => 'danger',
            self::Fuel => 'warning',
            self::LateReturn => 'warning',
            self::Cleaning => 'info',
            self::TrafficFine => 'danger',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Damage => 'heroicon-o-wrench',
            self::Fuel => 'heroicon-o-fire',
            self::LateReturn => 'heroicon-o-clock',
            self::Cleaning => 'heroicon-o-sparkles',
            self::TrafficFine => 'heroicon-o-exclamation-triangle',
            self::Other => 'heroicon-o-dots-horizontal',
        };
    }
}
