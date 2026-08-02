<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EmployeeStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnLeave => 'warning',
            self::Suspended => 'warning',
            self::Terminated => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-o-check-circle',
            self::OnLeave => 'heroicon-o-briefcase',
            self::Suspended => 'heroicon-o-pause-circle',
            self::Terminated => 'heroicon-o-x-circle',
        };
    }
}
