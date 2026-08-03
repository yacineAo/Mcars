<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Filament\Admin\Resources\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as ShieldListRoles;

class ListRoles extends ShieldListRoles
{
    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        // No create — the role list is the UserRole enum, and the seeder is the
        // only sanctioned writer. A seventh role has no enum case, so its holders
        // could never log in (canAccessPanel() requires a UserRole case).
        return [];
    }
}
