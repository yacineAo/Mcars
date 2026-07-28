<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InsuranceType: string implements HasColor, HasLabel
{
    use HasEnumMeta;

    case Basic = 'basic';
    case Full = 'full';

    public function getColor(): string
    {
        return match ($this) {
            self::Basic => 'warning',
            self::Full => 'success',
        };
    }
}
