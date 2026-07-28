<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceType: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case OilChange = 'oil_change';
    case TireChange = 'tire_change';
    case Brakes = 'brakes';
    case Filters = 'filters';
    case GeneralService = 'general_service';
    case Repair = 'repair';
    case BodyWork = 'body_work';
    case Battery = 'battery';
    case Diagnostics = 'diagnostics';
    case Cleaning = 'cleaning';
    case Other = 'other';

    public function getIcon(): string
    {
        return match ($this) {
            self::OilChange => 'heroicon-o-beaker',
            self::TireChange => 'heroicon-o-circle-stack',
            self::Brakes => 'heroicon-o-stop',
            self::Filters => 'heroicon-o-funnel',
            self::GeneralService => 'heroicon-o-wrench-screwdriver',
            self::Repair => 'heroicon-o-wrench',
            self::BodyWork => 'heroicon-o-truck',
            self::Battery => 'heroicon-o-bolt',
            self::Diagnostics => 'heroicon-o-magnifying-glass',
            self::Cleaning => 'heroicon-o-sparkles',
            self::Other => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
