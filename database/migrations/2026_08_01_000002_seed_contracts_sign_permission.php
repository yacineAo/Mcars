<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Signing a contract freezes its terms, so the ContractResource "sign" action and
 * `ContractService::markSigned()` gate on `contracts.sign`. On an existing deployment
 * the action disappears for **every** user until the permission exists in the
 * database — Shield runs with `define_via_gate => false` and there is no
 * `Gate::before`, so an unseeded permission is denied to everyone rather than granted.
 *
 * Same guarantee as `2026_08_01_000001_seed_bookings_operate_permission.php`, for the
 * same reason: `RolePermissionSeeder` creates it, but nothing guarantees a seeder run
 * on deploy. Mirrors the seeder exactly and is idempotent.
 *
 * Why a signature permission rather than reusing `bookings.operate`: the person at the
 * desk actually signs the customer in, so the spread is the same operating one — the
 * permission exists so an accountant with `bookings.view` (who audits the books) can
 * never be the one to freeze a price into a document.
 *
 * @see database/seeders/RolePermissionSeeder.php
 * @see docs/02-filament-panels.md — role → visibility matrix
 */
return new class extends Migration
{
    /** @var array<string, list<UserRole>> */
    private const GRANTS = [
        'contracts.sign' => [
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
