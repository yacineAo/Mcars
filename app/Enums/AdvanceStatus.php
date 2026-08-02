<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The advance lifecycle. `requested` → approve (E61 posts, status becomes
 * `outstanding`) or → `rejected`; payroll recovery turns it `recovered`; a
 * `written_off` advance is unrecoverable.
 *
 * Recovery is deliberately single-shot: `employee_advances.recovered_in_payroll_item_id`
 * is one column, so multi-month recovery is not modelled (docs/resource/28).
 * `partially_recovered` was dropped from the documented set for the same
 * reason — nothing can ever set it.
 */
enum AdvanceStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Requested = 'requested';
    case Rejected = 'rejected';
    case Outstanding = 'outstanding';
    case Recovered = 'recovered';
    case WrittenOff = 'written_off';

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'info',
            self::Rejected => 'danger',
            self::Outstanding => 'warning',
            self::Recovered => 'success',
            self::WrittenOff => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Requested => 'heroicon-o-document-plus',
            self::Rejected => 'heroicon-o-x-circle',
            self::Outstanding => 'heroicon-o-clock',
            self::Recovered => 'heroicon-o-check-circle',
            self::WrittenOff => 'heroicon-o-minus-circle',
        };
    }
}
