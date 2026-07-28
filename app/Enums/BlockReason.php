<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BlockReason: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Maintenance = 'maintenance';
    case OwnerUse = 'owner_use';
    case InterBranchTransfer = 'inter_branch_transfer';
    case InsuranceClaim = 'insurance_claim';
    case Administrative = 'administrative';
    case Other = 'other';

    public function getColor(): string
    {
        return match ($this) {
            self::Maintenance => 'danger',
            self::OwnerUse => 'warning',
            self::InterBranchTransfer => 'info',
            self::InsuranceClaim => 'warning',
            self::Administrative => 'gray',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Maintenance => 'heroicon-o-wrench',
            self::OwnerUse => 'heroicon-o-user',
            self::InterBranchTransfer => 'heroicon-o-arrows-right-left',
            self::InsuranceClaim => 'heroicon-o-document-text',
            self::Administrative => 'heroicon-o-folder',
            self::Other => 'heroicon-o-dots-horizontal',
        };
    }
}
