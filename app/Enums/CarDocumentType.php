<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CarDocumentType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case Insurance = 'insurance';
    case RegistrationCard = 'registration_card';
    case TechnicalInspection = 'technical_inspection';
    case RoadTaxVignette = 'road_tax_vignette';
    case OwnershipTitle = 'ownership_title';
    case PurchaseInvoice = 'purchase_invoice';
    case GpsSubscription = 'gps_subscription';
    case Other = 'other';

    public function getIcon(): string
    {
        return match ($this) {
            self::Insurance => 'heroicon-o-shield-check',
            self::RegistrationCard => 'heroicon-o-identification',
            self::TechnicalInspection => 'heroicon-o-clipboard-document-check',
            self::RoadTaxVignette => 'heroicon-o-receipt-percent',
            self::OwnershipTitle => 'heroicon-o-document-text',
            self::PurchaseInvoice => 'heroicon-o-document-currency-dollar',
            self::GpsSubscription => 'heroicon-o-map',
            self::Other => 'heroicon-o-document',
        };
    }
}
