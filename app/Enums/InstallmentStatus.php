<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum InstallmentStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Waived = 'waived';
    case PartiallyPaid = 'partially_paid';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Waived => 'gray',
            self::PartiallyPaid => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Paid => 'heroicon-o-check-circle',
            self::Overdue => 'heroicon-o-exclamation-circle',
            self::Waived => 'heroicon-o-x-circle',
            self::PartiallyPaid => 'heroicon-o-adjustments-horizontal',
        };
    }
}
