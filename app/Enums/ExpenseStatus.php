<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ExpenseStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil',
            self::PendingApproval => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check',
            self::Paid => 'heroicon-o-banknotes',
            self::Rejected => 'heroicon-o-x-circle',
        };
    }
}
