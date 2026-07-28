<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum OwnershipType: string implements HasLabel
{
    use HasEnumMeta;

    case CompanyOwned = 'company_owned';
    case ThirdParty = 'third_party';
}
