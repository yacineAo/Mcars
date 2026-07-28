<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CarStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Available = 'available';
    case Reserved = 'reserved';
    case Rented = 'rented';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
    case Sold = 'sold';
    case ReturnedToOwner = 'returned_to_owner';

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Reserved => 'info',
            self::Rented => 'warning',
            self::Maintenance => 'danger',
            self::OutOfService => 'gray',
            self::Sold => 'gray',
            self::ReturnedToOwner => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Available => 'heroicon-o-check-circle',
            self::Reserved => 'heroicon-o-clock',
            self::Rented => 'heroicon-o-truck',
            self::Maintenance => 'heroicon-o-wrench',
            self::OutOfService => 'heroicon-o-x-circle',
            self::Sold => 'heroicon-o-currency-dollar',
            self::ReturnedToOwner => 'heroicon-o-user',
        };
    }

    public function isTerminal(): bool
    {
        return $this->is(self::Sold, self::ReturnedToOwner);
    }

    public function isBookable(): bool
    {
        return $this->is(self::Available);
    }
}
