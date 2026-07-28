<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Overdue = 'overdue';

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Active => 'success',
            self::Completed => 'gray',
            self::Cancelled => 'danger',
            self::NoShow => 'danger',
            self::Overdue => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil',
            self::Pending => 'heroicon-o-clock',
            self::Confirmed => 'heroicon-o-check-badge',
            self::Active => 'heroicon-o-truck',
            self::Completed => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
            self::NoShow => 'heroicon-o-eye-slash',
            self::Overdue => 'heroicon-o-exclamation-triangle',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Pending'),
            self::Confirmed => __('Confirmed'),
            self::Active => __('Active'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
            self::NoShow => __('No Show'),
            self::Overdue => __('Overdue'),
        };
    }

    public function isActiveOrConfirmed(): bool
    {
        return $this->is(self::Confirmed, self::Active, self::Overdue);
    }

    public function isTerminal(): bool
    {
        return $this->is(self::Completed, self::Cancelled, self::NoShow);
    }
}
