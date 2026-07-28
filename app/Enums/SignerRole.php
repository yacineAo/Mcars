<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum SignerRole: string implements HasLabel
{
    use HasEnumMeta;

    case Customer = 'customer';
    case AdditionalDriver = 'additional_driver';
    case CompanyRepresentative = 'company_representative';
    case Guarantor = 'guarantor';
}
