<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Bounced = 'bounced';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Refunded => 'gray',
            self::PartiallyRefunded => 'info',
            self::Bounced => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Completed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
            self::Refunded => 'heroicon-o-arrow-uturn-left',
            self::PartiallyRefunded => 'heroicon-o-adjustments-horizontal',
            self::Bounced => 'heroicon-o-exclamation-triangle',
        };
    }
}
