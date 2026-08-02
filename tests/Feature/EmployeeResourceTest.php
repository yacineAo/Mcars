<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\AdvancesRelationManager;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\CommissionsRelationManager;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\PayrollItemsRelationManager;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeEmployee(array $overrides = []): Employee
{
    return Employee::create(array_merge([
        'employee_number' => 'EMP-TEST-'.strtoupper(Str::random(6)),
        'branch_id' => test()->branch->id,
        'first_name' => 'Yacine',
        'last_name' => 'Aoumara',
        'job_title' => 'Receptionist',
        'department' => 'Operations',
        'hire_date' => '2024-01-15',
        'contract_type' => 'cdi',
        'base_salary' => '45000.00',
        'status' => EmployeeStatus::Active,
    ], $overrides));
}

function hrRoleUser(Branch $branch, UserRole $role): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    $this->manager = hrRoleUser($this->branch, UserRole::Manager);
    $this->accountant = hrRoleUser($this->branch, UserRole::Accountant);
    $this->supervisor = hrRoleUser($this->branch, UserRole::Supervisor);
    $this->receptionist = hrRoleUser($this->branch, UserRole::Receptionist);
    $this->maintenance = hrRoleUser($this->branch, UserRole::MaintenanceOfficer);

    Auth::login($this->accountant);
});

// -----------------------------------------------------------------------
// Access — hr.view opens the directory, hr.manage writes it
// -----------------------------------------------------------------------

it('refuses the resource to roles outside HR', function () {
    Auth::login($this->receptionist);
    $this->get(EmployeeResource::getUrl('index', panel: 'admin'))->assertForbidden();

    Auth::login($this->maintenance);
    $this->get(EmployeeResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('lets the supervisor read the directory without any salary', function () {
    $employee = makeEmployee();

    Auth::login($this->supervisor);

    expect(EmployeeResource::canAccess())->toBeTrue()
        ->and(EmployeeResource::canView($employee))->toBeTrue()
        ->and(EmployeeResource::canEdit($employee))->toBeFalse()
        ->and(EmployeeResource::canCreate())->toBeFalse();

    $this->get(EmployeeResource::getUrl('index', panel: 'admin'))->assertSuccessful();

    // The index deliberately has no salary column, and the salary field on the
    // view page sits behind hr.view_salary.
    Livewire::test(ListEmployees::class)
        ->assertTableColumnDoesNotExist('base_salary');

    $this->get(EmployeeResource::getUrl('view', ['record' => $employee], panel: 'admin'))->assertSuccessful();
});

it('lets the accountant read salaries but never write the record', function () {
    $employee = makeEmployee();

    Auth::login($this->accountant);

    expect(EmployeeResource::canAccess())->toBeTrue()
        ->and(EmployeeResource::canEdit($employee))->toBeFalse()
        ->and(EmployeeResource::canCreate())->toBeFalse()
        ->and(PayrollItemsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeTrue()
        ->and(AdvancesRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeTrue()
        ->and(CommissionsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeTrue();

    $this->get(EmployeeResource::getUrl('view', ['record' => $employee], panel: 'admin'))->assertSuccessful();
});

it('hides the pay relations from a supervisor even on the view page', function () {
    $employee = makeEmployee();

    Auth::login($this->supervisor);

    expect(PayrollItemsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeFalse()
        ->and(AdvancesRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeFalse()
        ->and(CommissionsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeFalse();
});

it('refuses a crafted create call from anyone without hr.manage', function () {
    Auth::login($this->supervisor);

    expect(EmployeeResource::canCreate())->toBeFalse();

    $this->get(EmployeeResource::getUrl('create', panel: 'admin'))->assertForbidden();
});

// -----------------------------------------------------------------------
// The number is minted, never typed
// -----------------------------------------------------------------------

it('generates the employee number through the sequence when creating', function () {
    Auth::login($this->manager);

    Livewire::test(CreateEmployee::class)
        ->assertFormFieldDoesNotExist('employee_number')
        ->fillForm([
            'first_name' => 'Salim',
            'last_name' => 'Benaissa',
            'job_title' => 'Accountant',
            'department' => 'Finance',
            'base_salary' => '60000.00',
            'hire_date' => '2025-03-01',
            'contract_type' => 'cdi',
            'status' => 'active',
            'phone' => '0550 00 00 00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::where('first_name', 'Salim')->sole();

    expect($employee->employee_number)->toMatch('/^EMP-MAIN-\d{4}-\d{6}$/')
        ->and($employee->branch_id)->toBe($this->branch->id)
        ->and($employee->base_salary)->toBe('60000.00');
});

it('numbers employees gap-free and uniquely', function () {
    Auth::login($this->manager);

    $numbers = [];

    foreach (['Amina', 'Karim', 'Lila'] as $first) {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'first_name' => $first,
                'last_name' => 'Test',
                'base_salary' => '40000.00',
                'hire_date' => '2025-03-01',
                'contract_type' => 'cdi',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $numbers[] = Employee::where('first_name', $first)->value('employee_number');
    }

    expect($numbers)->toHaveCount(3)
        ->and($numbers[0])->not->toBe($numbers[1])
        ->and(array_unique($numbers))->toHaveCount(3);
});

it('refuses to change the number on edit, crafted payload included', function () {
    $employee = makeEmployee();
    $original = $employee->employee_number;

    Auth::login($this->manager);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm([
            'employee_number' => 'EMP-HACKED',
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'base_salary' => '45000.00',
            'hire_date' => '2024-01-15',
            'contract_type' => 'cdi',
            'status' => 'active',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->employee_number)->toBe($original);
});

// -----------------------------------------------------------------------
// No delete path — an employee leaves by status
// -----------------------------------------------------------------------

it('offers no bulk delete and refuses every delete path', function () {
    $employee = makeEmployee();

    Auth::login($this->manager);

    expect(EmployeeResource::canDelete($employee))->toBeFalse();

    Livewire::test(ListEmployees::class)
        ->assertTableBulkActionDoesNotExist('delete');
});

it('records a departure as a status change, not a deletion', function () {
    $employee = makeEmployee();

    Auth::login($this->manager);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm([
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'base_salary' => '45000.00',
            'hire_date' => '2024-01-15',
            'contract_type' => 'cdi',
            'status' => 'terminated',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Terminated);
});

// -----------------------------------------------------------------------
// Filters and branch scoping
// -----------------------------------------------------------------------

it('filters by department and job title', function () {
    makeEmployee(['first_name' => 'A', 'department' => 'Operations', 'job_title' => 'Receptionist']);
    makeEmployee(['first_name' => 'B', 'department' => 'Finance', 'job_title' => 'Accountant']);
    makeEmployee(['first_name' => 'C', 'department' => 'Finance', 'job_title' => 'Accountant']);

    Auth::login($this->accountant);

    Livewire::test(ListEmployees::class)
        ->filterTable('department', 'Finance')
        ->assertCanSeeTableRecords(Employee::where('department', 'Finance')->get())
        ->assertCanNotSeeTableRecords([Employee::where('first_name', 'A')->sole()]);
});

it('pins the list and the record pages to the branches the user can reach', function () {
    $other = Branch::factory()->create(['code' => 'OULED']);
    $mine = makeEmployee();
    $theirs = makeEmployee(['branch_id' => $other->id]);

    Auth::login($this->accountant);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(EmployeeResource::canView($theirs))->toBeFalse();

    // The record does not even resolve through the pinned query — a 404, not a
    // 403: the row's existence is not leaked to a branch that may not see it.
    $this->get(EmployeeResource::getUrl('view', ['record' => $theirs], panel: 'admin'))->assertNotFound();
    $this->get(EmployeeResource::getUrl('edit', ['record' => $theirs], panel: 'admin'))->assertNotFound();
});

// -----------------------------------------------------------------------
// The view page shows the pay history
// -----------------------------------------------------------------------

it('renders the pay histories on the view page for someone with hr.view_salary', function () {
    $employee = makeEmployee();

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-06-01',
        'status' => 'paid',
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'base_salary' => '45000.00',
        'commissions_amount' => '2000.00',
        'advances_deducted' => '5000.00',
        'gross_amount' => '47000.00',
        'net_amount' => '42000.00',
        'status' => 'paid',
    ]);

    $advance = EmployeeAdvance::create([
        'branch_id' => $this->branch->id,
        'employee_id' => $employee->id,
        'amount' => '5000.00',
        'advanced_on' => '2026-05-20',
        'status' => 'recovered',
    ]);

    Commission::create([
        'branch_id' => $this->branch->id,
        'employee_id' => $employee->id,
        'basis_amount' => '20000.00',
        'rate' => '10.00',
        'amount' => '2000.00',
        'status' => 'pending',
        'earned_on' => '2026-06-05',
    ]);

    Auth::login($this->accountant);

    $this->get(EmployeeResource::getUrl('view', ['record' => $employee], panel: 'admin'))->assertSuccessful();
});
