<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AgreementStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Ended => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-document-text',
            self::Active => 'heroicon-o-check-circle',
            self::Suspended => 'heroicon-o-pause',
            self::Ended => 'heroicon-o-x-circle',
        };
    }

    public function isTerminal(): bool
    {
        return $this->is(self::Ended);
    }
}
