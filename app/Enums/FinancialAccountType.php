<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FinancialAccountType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case CashBox = 'cash_box';
    case Bank = 'bank';
    case Ccp = 'ccp';
    case BaridiMob = 'baridimob';
    case CardTerminal = 'card_terminal';
    case Safe = 'safe';

    public function getColor(): string
    {
        return match ($this) {
            self::CashBox => 'success',
            self::Bank => 'info',
            self::Ccp => 'warning',
            self::BaridiMob => 'danger',
            self::CardTerminal => 'primary',
            self::Safe => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::CashBox => 'heroicon-o-cube',
            self::Bank => 'heroicon-o-building-library',
            self::Ccp => 'heroicon-o-envelope',
            self::BaridiMob => 'heroicon-o-device-phone-mobile',
            self::CardTerminal => 'heroicon-o-credit-card',
            self::Safe => 'heroicon-o-shield-check',
        };
    }
}
