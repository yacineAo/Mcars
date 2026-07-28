<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FineType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Speeding = 'speeding';
    case Parking = 'parking';
    case TrafficViolation = 'traffic_violation';
    case TollEvasion = 'toll_evasion';
    case Other = 'other';

    public function getColor(): string
    {
        return match ($this) {
            self::Speeding => 'danger',
            self::Parking => 'warning',
            self::TrafficViolation => 'danger',
            self::TollEvasion => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Speeding => 'heroicon-o-bolt',
            self::Parking => 'heroicon-o-stop',
            self::TrafficViolation => 'heroicon-o-exclamation-triangle',
            self::TollEvasion => 'heroicon-o-truck',
            self::Other => 'heroicon-o-dots-horizontal',
        };
    }
}
