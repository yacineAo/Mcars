<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DepositStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Held = 'held';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Forfeited = 'forfeited';

    public function getColor(): string
    {
        return match ($this) {
            self::Held => 'warning',
            self::PartiallyRefunded => 'info',
            self::Refunded => 'success',
            self::Forfeited => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Held => 'heroicon-o-lock-closed',
            self::PartiallyRefunded => 'heroicon-o-adjustments-horizontal',
            self::Refunded => 'heroicon-o-arrow-uturn-left',
            self::Forfeited => 'heroicon-o-no-symbol',
        };
    }
}
