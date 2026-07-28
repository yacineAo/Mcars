<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FineStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case New = 'new';
    case PendingReview = 'pending_review';
    case AssignedToCustomer = 'assigned_to_customer';
    case Disputed = 'disputed';
    case PaidByCompany = 'paid_by_company';
    case RecoveredFromCustomer = 'recovered_from_customer';
    case DeductedFromDeposit = 'deducted_from_deposit';
    case Closed = 'closed';
    case Pending = 'pending';
    case Paid = 'paid';
    case Waived = 'waived';
    case WrittenOff = 'written_off';

    public function getColor(): string
    {
        return match ($this) {
            self::New, self::PendingReview, self::Pending => 'warning',
            self::AssignedToCustomer => 'info',
            self::Disputed => 'danger',
            self::PaidByCompany, self::RecoveredFromCustomer,
            self::DeductedFromDeposit, self::Paid => 'success',
            self::Waived, self::Closed => 'gray',
            self::WrittenOff => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::New => 'heroicon-o-inbox-arrow-down',
            self::PendingReview, self::Pending => 'heroicon-o-clock',
            self::AssignedToCustomer => 'heroicon-o-user',
            self::Disputed => 'heroicon-o-exclamation-triangle',
            self::PaidByCompany, self::Paid => 'heroicon-o-check-circle',
            self::RecoveredFromCustomer => 'heroicon-o-arrow-uturn-left',
            self::DeductedFromDeposit => 'heroicon-o-scissors',
            self::Closed => 'heroicon-o-archive-box',
            self::Waived => 'heroicon-o-x-circle',
            self::WrittenOff => 'heroicon-o-document-minus',
        };
    }
}
