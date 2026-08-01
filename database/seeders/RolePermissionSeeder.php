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
            // The bookings catalogue (extras, contract templates). Every staff role
            // reads it except the maintenance officer, whose Bookings row in the
            // visibility matrix is scoped to blocks only — a receptionist must see
            // the extras list while quoting, and an accountant audits the prices.
            // Writing it changes what customers are charged, so that is the
            // manager's call alone.
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
            // Working an actual booking — quoting it, confirming it, handing over the
            // keys, taking the car back, taking money. Distinct from the catalogue
            // above: `bookings.manage` decides what a rental *costs*, this decides
            // that one happened. The visibility matrix gives the receptionist and the
            // supervisor "full" on Bookings, and a receptionist who cannot check a car
            // out cannot do the job, so the catalogue's manager-only spread would be
            // the wrong gate. The accountant keeps `bookings.view` and audits.
            'bookings.operate' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Receptionist,
                UserRole::Supervisor,
            ],
            // Affixing a signature to a rental contract and freezing its terms. The
            // document embeds prices, so the signature is the moment the customer
            // accepts them — it follows the operating spread rather than the
            // catalogue's manager-only one, mirroring bookings.operate. The
            // accountant signs nothing and audits the signed record.
            'contracts.sign' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Receptionist,
                UserRole::Supervisor,
            ],
            // Operating the till — opening and closing a cash session. The matrix gives
            // the receptionist "payments + cash only" on Finance, and closing posts a
            // variance to the ledger, so the two halves of the feature are split:
            // operate the till here, see the variance under reports.view_financials.
            // An accountant audits but does not operate a till, mirroring the
            // reverse_transaction reasoning — the role that answers for the books does
            // not touch the drawer.
            'cash_sessions.operate' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Receptionist,
            ],
            // Recording expenses — create, edit, submit for approval, delete an
            // unposted draft. Broad on purpose: the counter clerk who watched the
            // fuel go in is the one who records it (Finance row of the visibility
            // matrix: receptionist handles cash). Recording alone grants nothing
            // else: the clerk can neither approve nor pay.
            'expenses.record' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Accountant,
                UserRole::Receptionist,
            ],
            // Approval is the manager's control over what reaches the ledger: the
            // pending queue is a manager's worklist. Deliberately not granted to
            // the accountant, so recording is never enough to push an entry
            // through — only the manager (or super admin) can sign it off, and an
            // accountant who recorded an expense must still have it approved.
            'expenses.approve' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
            ],
            // Paying posts E39 to the ledger. The accountant answers for the
            // books, so they may pay entries others recorded — including, if they
            // recorded them, their own: the approval gate above is the control
            // that keeps a recorder from moving money through the ledger unchecked.
            'expenses.pay' => [
                UserRole::SuperAdmin,
                UserRole::Manager,
                UserRole::Accountant,
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
