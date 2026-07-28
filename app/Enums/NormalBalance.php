<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum NormalBalance: string implements HasLabel
{
    use HasEnumMeta;

    case Debit = 'debit';
    case Credit = 'credit';
}
