<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CustomerDocumentType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case NationalId = 'national_id';
    case DrivingLicense = 'driving_license';
    case Passport = 'passport';
    case ResidenceProof = 'residence_proof';
    case CompanyRegister = 'company_register';
    case Other = 'other';

    public function getIcon(): string
    {
        return match ($this) {
            self::NationalId => 'heroicon-o-identification',
            self::DrivingLicense => 'heroicon-o-document-text',
            self::Passport => 'heroicon-o-book-open',
            self::ResidenceProof => 'heroicon-o-home',
            self::CompanyRegister => 'heroicon-o-building-office',
            self::Other => 'heroicon-o-document',
        };
    }
}
