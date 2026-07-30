<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `CarResource::canAccess()` gates on `fleet.view`, so on an existing deployment the whole
 * Fleet section disappears for **every** user — super_admin included — until the permission
 * exists in the database. Shield runs with `define_via_gate => false` and there is no
 * `Gate::before`, so an unseeded permission is denied to everyone rather than granted.
 *
 * `RolePermissionSeeder` creates these, but nothing guarantees a seeder run on deploy. This
 * migration is that guarantee. It mirrors the seeder exactly and is idempotent, so running
 * both in either order is safe.
 *
 * @see database/seeders/RolePermissionSeeder.php
 */
return new class extends Migration
{
    /** @var array<string, list<UserRole>> */
    private const GRANTS = [
        'fleet.view' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
            UserRole::Accountant,
            UserRole::Receptionist,
            UserRole::MaintenanceOfficer,
            UserRole::Supervisor,
        ],
        'fleet.manage' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
        ],
        'fleet.manage_maintenance' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
            UserRole::MaintenanceOfficer,
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $permissionName => $roles) {
            $permission = Permission::findOrCreate($permissionName, 'web');

            foreach ($roles as $roleEnum) {
                $role = Role::findOrCreate($roleEnum->value, 'web');

                if (! $role->hasPermissionTo($permission)) {
                    $role->permissions()->attach($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', array_keys(self::GRANTS))
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Permission $permission) => $permission->delete());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
