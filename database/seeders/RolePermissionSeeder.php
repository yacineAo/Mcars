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
            // Staff accounts. docs/02-filament-panels.md §Role → visibility matrix puts
            // "Settings & Access" at full for a manager, so a manager creating a
            // receptionist is intended. What is not intended is elevation: the roles a
            // user may hand out are capped at their own in UserResource::assignableRoles().
            //
            // This replaces UserResource's old gate on branches.view_all, which governs
            // cross-branch visibility and only granted account management by accident.
            'users.manage' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
            // The ledger is append-only (ADR-003), so a mis-posting is corrected by a
            // reversal row and by nothing else. ViewTransaction has always gated its
            // reverse action on this permission, but the permission was never created —
            // and because Shield's super_admin runs with 'define_via_gate' => false and
            // there is no Gate::before, an unseeded permission is denied to *everyone*.
            // The only sanctioned correction path in the system was therefore unreachable.
            //
            // Deliberately not granted to a manager: reversing a posting is an accounting
            // act, and the accountant is the role that answers for the books.
            'reverse_transaction' => [
                UserRole::SuperAdmin,
                UserRole::Accountant,
            ],
            // Fleet, per docs/02-filament-panels.md §Role → visibility matrix: the row
            // reads "full | read | read | full (maintenance), read (rest) | read", so
            // every staff role reads the fleet — a receptionist cannot pick a car to
            // rent out without seeing it.
            'fleet.view' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Accountant,
                UserRole::Receptionist,
                UserRole::MaintenanceOfficer,
                UserRole::Supervisor,
            ],
            // Writing the car record itself — plate, VIN, rates, ownership. "full" in
            // the matrix belongs to the manager alone. The maintenance officer's "full"
            // is scoped to maintenance and is fleet.manage_maintenance below, not this:
            // a mechanic who may complete a service may not re-price the car.
            'fleet.manage' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
            // The "(maintenance)" half of the maintenance officer's row — logs and
            // schedules. Separate from fleet.manage so the officer's write access stops
            // at the workshop record. Note this permission gates an action that posts
            // E41 to the ledger, so it is deliberately narrower than fleet.view.
            'fleet.manage_maintenance' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::MaintenanceOfficer,
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
