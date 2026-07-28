<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function getColor(): string
    {
        return match ($this) {
            self::Asset => 'success',
            self::Liability => 'warning',
            self::Equity => 'info',
            self::Revenue => 'success',
            self::Expense => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Asset => 'heroicon-o-banknotes',
            self::Liability => 'heroicon-o-credit-card',
            self::Equity => 'heroicon-o-presentation-chart-bar',
            self::Revenue => 'heroicon-o-arrow-trending-up',
            self::Expense => 'heroicon-o-arrow-trending-down',
        };
    }
}
