<?php

declare(strict_types=1);

use App\Enums\CommissionStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\CommissionResource;
use App\Filament\Admin\Resources\CommissionResource\Pages\CreateCommission;
use App\Filament\Admin\Resources\CommissionResource\Pages\EditCommission;
use App\Filament\Admin\Resources\CommissionResource\Pages\ListCommissions;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payment\CommissionService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCommissionEmployee(array $overrides = []): Employee
{
    return Employee::create(array_merge([
        'employee_number' => 'EMP-COM-'.strtoupper(Str::random(6)),
        'branch_id' => test()->branch->id,
        'first_name' => 'Yasmine',
        'last_name' => 'Meziane',
        'job_title' => 'Sales Agent',
        'department' => 'Sales',
        'hire_date' => '2024-01-15',
        'contract_type' => 'cdi',
        'base_salary' => '30000.00',
        'status' => 'active',
    ], $overrides));
}

function makeCommission(array $overrides = []): Commission
{
    return Commission::create(array_merge([
        'branch_id' => test()->branch->id,
        'employee_id' => test()->employee->id,
        'booking_id' => null,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'amount' => '2000.00',
        'status' => CommissionStatus::Pending,
        'earned_on' => '2026-07-20',
        'notes' => null,
    ], $overrides));
}

function commissionRoleUser(Branch $branch, UserRole $role): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    $this->manager = commissionRoleUser($this->branch, UserRole::Manager);
    $this->accountant = commissionRoleUser($this->branch, UserRole::Accountant);
    $this->supervisor = commissionRoleUser($this->branch, UserRole::Supervisor);
    $this->receptionist = commissionRoleUser($this->branch, UserRole::Receptionist);

    $this->employee = makeCommissionEmployee();

    Auth::login($this->accountant);
});

// -----------------------------------------------------------------------
// Access — hr.view_salary opens the list, hr.manage drives the writes
// -----------------------------------------------------------------------

it('refuses the resource to roles without the payroll permission', function () {
    Auth::login($this->receptionist);
    $this->get(CommissionResource::getUrl('index', panel: 'admin'))->assertForbidden();

    Auth::login($this->supervisor);
    $this->get(CommissionResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('lets the accountant read but never create, edit or delete', function () {
    $commission = makeCommission();

    expect(CommissionResource::canAccess())->toBeTrue()
        ->and(CommissionResource::canCreate())->toBeFalse()
        ->and(CommissionResource::canEdit($commission))->toBeFalse()
        ->and(CommissionResource::canDelete($commission))->toBeFalse();

    $this->get(CommissionResource::getUrl('index', panel: 'admin'))->assertSuccessful();

    // The edit affordance renders like the advance's — the guard is at the
    // page mount (canEdit), which the direct URL checks below.
    Livewire::test(ListCommissions::class)
        ->assertTableActionExists('edit');
});

it('lets the manager create and edit', function () {
    Auth::login($this->manager);

    expect(CommissionResource::canAccess())->toBeTrue()
        ->and(CommissionResource::canCreate())->toBeTrue();

    $this->get(CommissionResource::getUrl('create', panel: 'admin'))->assertSuccessful();
});

it('refuses a crafted create call from the accountant', function () {
    $this->get(CommissionResource::getUrl('create', panel: 'admin'))->assertForbidden();
});

// -----------------------------------------------------------------------
// Creating: pending by default, amount computed by the service
// -----------------------------------------------------------------------

it('creates a commission with the amount computed from basis and rate', function () {
    Auth::login($this->manager);

    Livewire::test(CreateCommission::class)
        ->fillForm([
            'employee_id' => $this->employee->id,
            'basis_amount' => '20000.00',
            'rate' => '10.00',
            'earned_on' => '2026-07-20',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $commission = Commission::where('employee_id', $this->employee->id)->sole();

    expect($commission->amount)->toBe('2000.00')
        ->and($commission->status)->toBe(CommissionStatus::Pending)
        ->and($commission->branch_id)->toBe($this->branch->id);
});

it('rounds basis × rate to the centime in integer minor units', function () {
    Auth::login($this->manager);

    Livewire::test(CreateCommission::class)
        ->fillForm([
            'employee_id' => $this->employee->id,
            'basis_amount' => '3333.33',
            'rate' => '15.00',
            'earned_on' => '2026-07-20',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // 3333.33 × 0.15 = 499.9995 — half-up to 500.00, never float dust.
    expect(Commission::sole()->amount)->toBe('500.00');
});

it('refuses a commission on your own employee record', function () {
    Auth::login($this->manager);

    makeCommissionEmployee(['user_id' => $this->manager->id]);

    Livewire::test(CreateCommission::class)
        ->fillForm([
            'employee_id' => Employee::where('user_id', $this->manager->id)->sole()->id,
            'basis_amount' => '20000.00',
            'rate' => '10.00',
            'earned_on' => '2026-07-20',
        ])
        ->call('create')
        ->assertHasFormErrors(['employee_id']);

    expect(Commission::count())->toBe(0);
});

it('ignores a crafted status and amount on create — the service owns both', function () {
    Auth::login($this->manager);

    $commission = app(CommissionService::class)->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'amount' => '1.00',
        'status' => CommissionStatus::Paid->value,
        'earned_on' => '2026-07-20',
    ], $this->manager->id);

    expect($commission->status)->toBe(CommissionStatus::Pending)
        ->and($commission->amount)->toBe('2000.00');
});

it('ignores a crafted payroll_item_id on create — the sweep stamp is never typed', function () {
    Auth::login($this->manager);

    $item = PayrollItem::create([
        'payroll_run_id' => PayrollRun::create([
            'branch_id' => $this->branch->id,
            'period_month' => '2026-08-01',
            'status' => 'approved',
        ])->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '30000.00',
        'gross_amount' => '32000.00',
        'net_amount' => '32000.00',
        'status' => 'approved',
    ]);

    $commission = app(CommissionService::class)->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'payroll_item_id' => $item->id,
        'earned_on' => '2026-07-20',
    ], $this->manager->id);

    // A forged stamp would claim a payment the ledger has not made and drop
    // the commission out of the unpaid sweep queue.
    expect($commission->payroll_item_id)->toBeNull()
        ->and($commission->amount)->toBe('2000.00');
});

it('ignores a crafted payroll_item_id on update — the stamp cannot be forged', function () {
    $commission = makeCommission();

    $item = PayrollItem::create([
        'payroll_run_id' => PayrollRun::create([
            'branch_id' => $this->branch->id,
            'period_month' => '2026-08-01',
            'status' => 'approved',
        ])->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '30000.00',
        'gross_amount' => '32000.00',
        'net_amount' => '32000.00',
        'status' => 'approved',
    ]);

    Auth::login($this->manager);

    $updated = app(CommissionService::class)->update($commission, [
        'employee_id' => $this->employee->id,
        'basis_amount' => '20000.00',
        'rate' => '12.50',
        'payroll_item_id' => $item->id,
        'earned_on' => '2026-07-20',
    ], $this->manager->id);

    expect($updated->payroll_item_id)->toBeNull()
        ->and($updated->amount)->toBe('2500.00');
});

it('refuses to edit a commission in a paid state even without the stamp', function () {
    $commission = makeCommission(['status' => CommissionStatus::Paid]);

    Auth::login($this->manager);

    expect(fn () => app(CommissionService::class)->update($commission, [
        'employee_id' => $this->employee->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'earned_on' => '2026-07-20',
    ], $this->manager->id))->toThrow(DomainException::class);
});

it('refuses a commission for an employee of another branch', function () {
    $other = Branch::factory()->create(['code' => 'OULED']);
    makeCommissionEmployee(['branch_id' => $other->id]);

    // The accountant is pinned to the home branch (no branches.view_all): the
    // service re-checks what the form already pinned.
    $outsider = Employee::where('branch_id', $other->id)->sole();

    expect(fn () => app(CommissionService::class)->create([
        'employee_id' => $outsider->id,
        'branch_id' => $this->branch->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'earned_on' => '2026-07-20',
    ], $this->accountant->id))->toThrow(DomainException::class);

    expect(Commission::count())->toBe(0);
});

it('refuses a commission for a nonexistent employee instead of a foreign-key 500', function () {
    Auth::login($this->manager);

    expect(fn () => app(CommissionService::class)->create([
        'employee_id' => 999999,
        'branch_id' => $this->branch->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'earned_on' => '2026-07-20',
    ], $this->manager->id))->toThrow(DomainException::class);

    expect(Commission::count())->toBe(0);
});

// -----------------------------------------------------------------------
// Editing: rate corrections until the sweep, frozen after
// -----------------------------------------------------------------------

it('recomputes the amount when the rate is corrected before payment', function () {
    $commission = makeCommission();

    Auth::login($this->manager);

    Livewire::test(EditCommission::class, ['record' => $commission->getKey()])
        ->fillForm([
            'basis_amount' => '20000.00',
            'rate' => '12.50',
            'earned_on' => '2026-07-20',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($commission->fresh()->amount)->toBe('2500.00');
});

it('refuses to edit a commission already swept into payroll', function () {
    $commission = makeCommission();
    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-08-01',
        'status' => 'approved',
    ]);
    $item = PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '30000.00',
        'commissions_amount' => '2000.00',
        'gross_amount' => '32000.00',
        'net_amount' => '32000.00',
        'status' => 'approved',
    ]);
    $commission->update(['payroll_item_id' => $item->id]);

    Auth::login($this->manager);

    Livewire::test(EditCommission::class, ['record' => $commission->getKey()])
        ->assertFormFieldDisabled('employee_id')
        ->assertFormFieldDisabled('booking_id')
        ->assertFormFieldDisabled('basis_amount')
        ->assertFormFieldDisabled('rate')
        ->assertFormFieldDisabled('earned_on');

    expect(fn () => app(CommissionService::class)->update($commission->fresh(), [
        'employee_id' => $this->employee->id,
        'basis_amount' => '9000.00',
        'rate' => '10.00',
        'earned_on' => '2026-07-20',
    ], $this->manager->id))->toThrow(DomainException::class);

    expect($commission->fresh()->amount)->toBe('2000.00');
});

it('keeps the status server-owned and untyped', function () {
    $commission = makeCommission();

    Auth::login($this->manager);

    $updated = app(CommissionService::class)->update($commission, [
        'employee_id' => $this->employee->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'status' => CommissionStatus::Cancelled->value,
        'earned_on' => '2026-07-20',
    ], $this->manager->id);

    expect($updated->status)->toBe(CommissionStatus::Pending);
});

// -----------------------------------------------------------------------
// The list: unpaid by default, the sweep stamp as a derived column
// -----------------------------------------------------------------------

it('shows only unpaid commissions by default — the sweep queue', function () {
    $unpaid = makeCommission(['amount' => '1000.00']);
    $swept = makeCommission(['amount' => '2000.00']);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-08-01',
        'status' => 'approved',
    ]);
    $item = PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '30000.00',
        'commissions_amount' => '2000.00',
        'gross_amount' => '32000.00',
        'net_amount' => '32000.00',
        'status' => 'approved',
    ]);
    $swept->update(['payroll_item_id' => $item->id]);

    Auth::login($this->manager);

    Livewire::test(ListCommissions::class)
        ->assertCanSeeTableRecords([$unpaid])
        ->assertCanNotSeeTableRecords([$swept]);
});

it('shows the payroll run that swept a commission in as a derived column', function () {
    $commission = makeCommission();

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-08-01',
        'status' => 'approved',
    ]);
    $item = PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '30000.00',
        'commissions_amount' => '2000.00',
        'gross_amount' => '32000.00',
        'net_amount' => '32000.00',
        'status' => 'approved',
    ]);
    $commission->update(['payroll_item_id' => $item->id]);

    Auth::login($this->manager);

    Livewire::test(ListCommissions::class)
        ->filterTable('unpaid', 0)
        ->assertTableColumnStateSet(
            'payrollItem.payrollRun.period_month',
            $commission->fresh()->payrollItem->payrollRun->period_month,
            $commission,
        );
});

it('filters the list by earned_on range', function () {
    $july = makeCommission(['earned_on' => '2026-07-20']);
    $september = makeCommission(['earned_on' => '2026-09-20']);

    Auth::login($this->manager);

    Livewire::test(ListCommissions::class)
        ->filterTable('earned_on_range', [
            'earned_from' => '2026-07-01',
            'earned_until' => '2026-07-31',
        ])
        ->assertCanSeeTableRecords([$july])
        ->assertCanNotSeeTableRecords([$september]);
});

// -----------------------------------------------------------------------
// No delete path — money records are not deleted
// -----------------------------------------------------------------------

it('offers no bulk delete and refuses every delete path', function () {
    $commission = makeCommission();

    Auth::login($this->manager);

    expect(CommissionResource::canDelete($commission))->toBeFalse();

    Livewire::test(ListCommissions::class)
        ->assertTableBulkActionDoesNotExist('delete');
});

// -----------------------------------------------------------------------
// Branch scoping
// -----------------------------------------------------------------------

it('pins the list and the record pages to the branches the user can reach', function () {
    $other = Branch::factory()->create(['code' => 'OULED']);
    $mine = makeCommission();
    $theirs = makeCommission(['branch_id' => $other->id, 'amount' => '9000.00']);

    // The accountant holds hr.view_salary but not branches.view_all, so the
    // pin is meaningful; the manager would see every branch by design.
    Auth::login($this->accountant);

    Livewire::test(ListCommissions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(CommissionResource::canView($theirs))->toBeFalse()
        ->and(CommissionResource::canEdit($theirs))->toBeFalse();

    $this->get(CommissionResource::getUrl('edit', ['record' => $theirs], panel: 'admin'))->assertNotFound();
});
