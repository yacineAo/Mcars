<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FineLiability: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Customer = 'customer';
    case Company = 'company';
    case Owner = 'owner';

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'danger',
            self::Company => 'warning',
            self::Owner => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Customer => 'heroicon-o-user',
            self::Company => 'heroicon-o-building-office',
            self::Owner => 'heroicon-o-user-group',
        };
    }
}
