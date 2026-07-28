<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $perm = Permission::findOrCreate('branches.view_all', 'web');

        foreach (UserRole::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value, 'web');
        }

        $superAdmin = Role::findByName(UserRole::SuperAdmin->value);
        $manager = Role::findByName(UserRole::Manager->value);

        if (! $superAdmin->hasPermissionTo($perm)) {
            $superAdmin->permissions()->attach($perm);
        }
        if (! $manager->hasPermissionTo($perm)) {
            $manager->permissions()->attach($perm);
        }
    }
}
