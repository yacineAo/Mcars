<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CashSessionStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Open = 'open';
    case Closed = 'closed';
    case Reconciled = 'reconciled';
    case Disputed = 'disputed';

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'warning',
            self::Reconciled => 'info',
            self::Disputed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-o-lock-open',
            self::Closed => 'heroicon-o-lock-closed',
            self::Reconciled => 'heroicon-o-check-circle',
            self::Disputed => 'heroicon-o-exclamation-circle',
        };
    }
}
