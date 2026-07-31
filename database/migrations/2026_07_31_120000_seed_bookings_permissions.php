<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `ExtraResource::canAccess()` gates on `bookings.view`, so on an existing deployment the
 * whole Bookings catalogue disappears for **every** user — super_admin included — until the
 * permission exists in the database. Shield runs with `define_via_gate => false` and there
 * is no `Gate::before`, so an unseeded permission is denied to everyone rather than granted.
 *
 * `RolePermissionSeeder` creates these, but nothing guarantees a seeder run on deploy. This
 * migration is that guarantee. It mirrors the seeder exactly and is idempotent, so running
 * both in either order is safe.
 *
 * The role spread mirrors the Bookings row of the visibility matrix in
 * docs/02-filament-panels.md: every staff role reads the bookings cluster except the
 * maintenance officer, whose read is scoped to blocks only. Writing the catalogue —
 * prices, codes, ledger mapping — is the manager's call alone: an extra's `unit_price`
 * is what customers are charged.
 *
 * @see database/seeders/RolePermissionSeeder.php
 * @see docs/02-filament-panels.md
 */
return new class extends Migration
{
    /** @var array<string, list<UserRole>> */
    private const GRANTS = [
        'bookings.view' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
            UserRole::Accountant,
            UserRole::Receptionist,
            UserRole::Supervisor,
        ],
        'bookings.manage' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
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
