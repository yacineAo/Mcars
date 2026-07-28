<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Admin\Resources\RoleResource\Pages\EditRole;
use App\Filament\Admin\Resources\RoleResource\Pages\ListRoles;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use UnitEnum;

class RoleResource extends ShieldRoleResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
