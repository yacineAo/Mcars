<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ConditionReportType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Checkout = 'checkout';
    case Checkin = 'checkin';

    public function getColor(): string
    {
        return match ($this) {
            self::Checkout => 'warning',
            self::Checkin => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Checkout => 'heroicon-o-arrow-right-circle',
            self::Checkin => 'heroicon-o-arrow-left-circle',
        };
    }
}
