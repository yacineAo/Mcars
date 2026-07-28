<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FineStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Pending = 'pending';
    case Paid = 'paid';
    case Waived = 'waived';
    case WrittenOff = 'written_off';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Waived => 'gray',
            self::WrittenOff => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Paid => 'heroicon-o-check-circle',
            self::Waived => 'heroicon-o-x-circle',
            self::WrittenOff => 'heroicon-o-document-minus',
        };
    }
}
