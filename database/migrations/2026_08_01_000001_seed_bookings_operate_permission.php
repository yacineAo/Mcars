<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `BookingResource` gates writing on `bookings.operate`, so on an existing deployment
 * every lifecycle action — confirm, checkout, checkin, cancel, record payment —
 * disappears for **every** user until the permission exists in the database. Shield
 * runs with `define_via_gate => false` and there is no `Gate::before`, so an unseeded
 * permission is denied to everyone rather than granted.
 *
 * Same guarantee as `2026_07_31_120000_seed_bookings_permissions.php`, for the same
 * reason: `RolePermissionSeeder` creates it, but nothing guarantees a seeder run on
 * deploy. Mirrors the seeder exactly and is idempotent.
 *
 * Why a third bookings permission rather than reusing `bookings.manage`: that one
 * governs the *catalogue* (extras, contract templates — what a rental costs) and is
 * deliberately manager-only. The Bookings row of the visibility matrix gives the
 * receptionist and the supervisor "full", and a receptionist who cannot hand over keys
 * cannot work the front desk.
 *
 * @see database/seeders/RolePermissionSeeder.php
 * @see docs/02-filament-panels.md — role → visibility matrix
 */
return new class extends Migration
{
    /** @var array<string, list<UserRole>> */
    private const GRANTS = [
        'bookings.operate' => [
            UserRole::SuperAdmin,
            UserRole::Manager,
            UserRole::Receptionist,
            UserRole::Supervisor,
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
