<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum BodyType: string implements HasLabel
{
    use HasEnumMeta;

    case Sedan = 'sedan';
    case Hatchback = 'hatchback';
    case Suv = 'suv';
    case Crossover = 'crossover';
    case Pickup = 'pickup';
    case Van = 'van';
    case Minibus = 'minibus';
    case Utility = 'utility';
    case Coupe = 'coupe';
}
