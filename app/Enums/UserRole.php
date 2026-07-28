<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Staff roles. Every one of them is an office role with access to the admin panel.
 *
 * There is deliberately no customer or car-owner role: the system is staff-only,
 * and both are records the office manages rather than accounts that log in. The
 * `user_id` columns on `customers` and `car_owners` are retained but unused — see
 * ADR-007.
 */
enum UserRole: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Receptionist = 'receptionist';
    case MaintenanceOfficer = 'maintenance_officer';
    case Supervisor = 'supervisor';

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Manager => 'primary',
            self::Accountant => 'warning',
            self::Receptionist => 'info',
            self::MaintenanceOfficer => 'gray',
            self::Supervisor => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::SuperAdmin => 'heroicon-o-shield-check',
            self::Manager => 'heroicon-o-user',
            self::Accountant => 'heroicon-o-calculator',
            self::Receptionist => 'heroicon-o-phone',
            self::MaintenanceOfficer => 'heroicon-o-wrench',
            self::Supervisor => 'heroicon-o-eye',
        };
    }
}
