<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Receptionist = 'receptionist';
    case MaintenanceOfficer = 'maintenance_officer';
    case Supervisor = 'supervisor';
    case CarOwner = 'car_owner';
    case Client = 'client';

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Manager => 'primary',
            self::Accountant => 'warning',
            self::Receptionist => 'info',
            self::MaintenanceOfficer => 'gray',
            self::Supervisor => 'success',
            self::CarOwner => 'purple',
            self::Client => 'secondary',
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
            self::CarOwner => 'heroicon-o-building-office',
            self::Client => 'heroicon-o-user-circle',
        };
    }

    public function panelId(): string
    {
        return match ($this) {
            self::CarOwner => 'owner',
            self::Client => 'client',
            default => 'admin',
        };
    }
}
