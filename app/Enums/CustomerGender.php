<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum CustomerGender: string implements HasLabel
{
    use HasEnumMeta;

    case Male = 'male';
    case Female = 'female';
}
