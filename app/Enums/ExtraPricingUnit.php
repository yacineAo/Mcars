<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum ExtraPricingUnit: string implements HasLabel
{
    use HasEnumMeta;

    case PerDay = 'per_day';
    case PerRental = 'per_rental';
    case PerKm = 'per_km';
}
