<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AgreementModel: string implements HasColor, HasLabel
{
    use HasEnumMeta;

    case FixedMonthly = 'fixed_monthly';
    case RevenueShare = 'revenue_share';
    case Hybrid = 'hybrid';

    public function getColor(): string
    {
        return match ($this) {
            self::FixedMonthly => 'info',
            self::RevenueShare => 'warning',
            self::Hybrid => 'success',
        };
    }
}
