<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Ccp = 'ccp';
    case Card = 'card';
    case BaridiMob = 'baridimob';
    case Cheque = 'cheque';
    case Compensation = 'compensation';

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::BankTransfer => 'info',
            self::Ccp => 'warning',
            self::Card => 'primary',
            self::BaridiMob => 'danger',
            self::Cheque => 'gray',
            self::Compensation => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'heroicon-o-banknotes',
            self::BankTransfer => 'heroicon-o-building-library',
            self::Ccp => 'heroicon-o-envelope',
            self::Card => 'heroicon-o-credit-card',
            self::BaridiMob => 'heroicon-o-device-phone-mobile',
            self::Cheque => 'heroicon-o-document-text',
            self::Compensation => 'heroicon-o-arrows-right-left',
        };
    }
}
