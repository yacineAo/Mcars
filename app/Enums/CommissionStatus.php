<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The commission lifecycle. Every commission starts `pending`; the payroll run
 * that sweeps it in moves it towards `paid`, and `cancelled` retires one the
 * business is not honouring. The sweep stamp is
 * `commissions.payroll_item_id` — while it is null the commission is unpaid.
 */
enum CommissionStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Approved => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check-badge',
            self::Paid => 'heroicon-o-banknotes',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }
}
