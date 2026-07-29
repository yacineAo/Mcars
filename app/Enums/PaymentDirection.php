<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Compensation = 'compensation';

    public function getColor(): string
    {
        return match ($this) {
            self::Inbound => 'success',
            self::Outbound => 'danger',
            self::Compensation => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Inbound => 'heroicon-o-arrow-down',
            self::Outbound => 'heroicon-o-arrow-up',
            self::Compensation => 'heroicon-o-arrows-right-left',
        };
    }
}
