<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    use HasEnumMeta;

    case Individual = 'individual';
    case Company = 'company';
}
