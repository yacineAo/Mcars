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

        foreach (UserRole::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value, 'web');
        }

        // Permission => the roles that hold it.
        $grants = [
            'branches.view_all' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
            // Gates revenue, profit, cash-flow and receivables reporting. A
            // receptionist runs the day's returns and the till without ever seeing
            // what the business earns.
            'reports.view_financials' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Accountant,
            ],
            // Phase 8. Lead times, channels and recipients are an operational
            // decision, so a manager owns them without a deploy — but changing a
            // rule changes who gets told what, which is not a receptionist's call.
            'alerts.manage' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
            // The delivery audit trail exposes recipient addresses and message
            // payloads, so it is read-only and deliberately narrower than the
            // ability to receive alerts.
            'alerts.view_logs' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
        ];

        foreach ($grants as $permissionName => $roles) {
            $permission = Permission::findOrCreate($permissionName, 'web');

            foreach ($roles as $roleEnum) {
                $role = Role::findByName($roleEnum->value);

                if (! $role->hasPermissionTo($permission)) {
                    $role->permissions()->attach($permission);
                }
            }
        }
    }
}
