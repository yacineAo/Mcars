<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ContractStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Draft = 'draft';
    case AwaitingSignature = 'awaiting_signature';
    case Signed = 'signed';
    case Active = 'active';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Amended = 'amended';

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::AwaitingSignature => 'warning',
            self::Signed => 'success',
            self::Active => 'info',
            self::Closed => 'gray',
            self::Cancelled => 'danger',
            self::Amended => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil',
            self::AwaitingSignature => 'heroicon-o-pencil-square',
            self::Signed => 'heroicon-o-check',
            self::Active => 'heroicon-o-play',
            self::Closed => 'heroicon-o-archive-box',
            self::Cancelled => 'heroicon-o-x-circle',
            self::Amended => 'heroicon-o-arrow-path',
        };
    }

    public function isTerminal(): bool
    {
        return $this->is(self::Closed, self::Cancelled);
    }
}
