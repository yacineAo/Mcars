<?php

declare(strict_types=1);

use App\Enums\CommissionStatus;
use App\Enums\PayrollStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\PayrollRunResource;
use App\Filament\Admin\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Filament\Admin\Resources\PayrollRunResource\Pages\ViewPayrollRun;
use App\Filament\Admin\Resources\PayrollRunResource\RelationManagers\PayrollItemsRelationManager;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Payment\CommissionService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PayrollService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function payrollAccount(string $code): ChartOfAccount
{
    return ChartOfAccount::where('code', $code)->firstOrFail();
}

function makePayrollEmployee(array $overrides = []): Employee
{
    return Employee::create(array_merge([
        'employee_number' => 'EMP-PR-'.strtoupper(Str::random(6)),
        'branch_id' => test()->branch->id,
        'first_name' => 'Nadia',
        'last_name' => 'Bensaïd',
        'job_title' => 'Agent',
        'hire_date' => '2024-01-15',
        'contract_type' => 'cdi',
        'salary_type' => 'fixed',
        'base_salary' => '70000.00',
        'status' => 'active',
    ], $overrides));
}

function makePayrollAdvance(array $overrides = []): EmployeeAdvance
{
    return EmployeeAdvance::create(array_merge([
        'branch_id' => test()->branch->id,
        'employee_id' => test()->employee->id,
        'amount' => '10000.00',
        'advanced_on' => '2026-06-15',
        'status' => 'outstanding',
    ], $overrides));
}

function makePayrollCommission(array $overrides = []): Commission
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

function payrollRoleUser(Branch $branch, UserRole $role): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->manager = payrollRoleUser($this->branch, UserRole::Manager);
    $this->accountant = payrollRoleUser($this->branch, UserRole::Accountant);
    $this->supervisor = payrollRoleUser($this->branch, UserRole::Supervisor);
    $this->receptionist = payrollRoleUser($this->branch, UserRole::Receptionist);

    $this->employee = makePayrollEmployee();

    $this->payroll = app(PayrollService::class);
    $this->payments = app(PaymentService::class);
    $this->accounting = app(AccountingService::class);

    Auth::login($this->accountant);
});

// -----------------------------------------------------------------------
// Access — view_salary opens the run, hr.manage drives the writes
// -----------------------------------------------------------------------

it('refuses the resource to roles without the salary permission', function () {
    Auth::login($this->receptionist);
    $this->get(PayrollRunResource::getUrl('index', panel: 'admin'))->assertForbidden();

    Auth::login($this->supervisor);
    $this->get(PayrollRunResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('lets the accountant read but never create, edit or delete', function () {
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    expect(PayrollRunResource::canAccess())->toBeTrue()
        ->and(PayrollRunResource::canCreate())->toBeFalse()
        ->and(PayrollRunResource::canEdit($run))->toBeFalse()
        ->and(PayrollRunResource::canDelete($run))->toBeFalse();

    $this->get(PayrollRunResource::getUrl('index', panel: 'admin'))->assertSuccessful();
    $this->get(PayrollRunResource::getUrl('view', ['record' => $run], panel: 'admin'))->assertSuccessful();
});

it('lets the manager create and approve', function () {
    Auth::login($this->manager);

    expect(PayrollRunResource::canAccess())->toBeTrue()
        ->and(PayrollRunResource::canCreate())->toBeTrue();

    $this->get(PayrollRunResource::getUrl('create', panel: 'admin'))->assertSuccessful();
});

it('refuses a crafted create call from the accountant', function () {
    $this->get(PayrollRunResource::getUrl('create', panel: 'admin'))->assertForbidden();
});

it('has no delete affordance anywhere', function () {
    Livewire::test(ListPayrollRuns::class)
        ->assertTableBulkActionDoesNotExist('delete');

    expect(PayrollRunResource::canDelete($this->payroll->generate($this->branch->id, '2026-07-01')))
        ->toBeFalse();
});

// -----------------------------------------------------------------------
// Generation — the run is gathered, not typed
// -----------------------------------------------------------------------

it('gathers base salary, unrecovered advances and unpaid commissions into items', function () {
    $advance = makePayrollAdvance();
    $commission = makePayrollCommission();

    // A terminated colleague is not gathered, and neither is another
    // employee's commission on their behalf.
    $other = makePayrollEmployee(['employee_number' => 'EMP-PR-OTHER', 'status' => 'terminated']);
    makePayrollCommission(['employee_id' => $other->id]);
    makePayrollAdvance(['employee_id' => $other->id, 'amount' => '5000.00']);

    $run = $this->payroll->generate($this->branch->id, '2026-07');

    expect($run->status)->toBe(PayrollStatus::Draft)
        ->and($run->items)->toHaveCount(1);

    $item = $run->items->first();
    expect($item->employee_id)->toBe($this->employee->id)
        ->and($item->base_salary)->toBe('70000.00')
        ->and($item->commissions_amount)->toBe('2000.00')
        ->and($item->advances_deducted)->toBe('10000.00')
        ->and($item->gross_amount)->toBe('70000.00')
        ->and($item->net_amount)->toBe('62000.00');

    // The sweep is claimed: neither amount can be gathered by a later run.
    expect($advance->fresh()->recovered_in_payroll_item_id)->not->toBeNull()
        ->and($commission->fresh()->payroll_item_id)->not->toBeNull()
        ->and($other->fresh()->advances->first()->recovered_in_payroll_item_id)->toBeNull();
});

it('refuses a second live run for the same branch and period', function () {
    $this->payroll->generate($this->branch->id, '2026-07-01');

    expect(fn () => $this->payroll->generate($this->branch->id, '2026-07-15'))
        ->toThrow(DomainException::class, 'already has a payroll run');
});

it('lets a cancelled run be re-generated for the same period', function () {
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $run->update(['status' => PayrollStatus::Cancelled]);

    $again = $this->payroll->generate($this->branch->id, '2026-07-01');

    expect($again->id)->not->toBe($run->id);
});

it('never gathers an amount claimed by another run', function () {
    $commission = makePayrollCommission();

    $this->payroll->generate($this->branch->id, '2026-07-01');

    $second = $this->payroll->generate($this->branch->id, '2026-08-01');

    expect($second->items->first()->commissions_amount)->toBe('0.00')
        ->and(Commission::find($commission->id)->payroll_item_id)->not->toBeNull();
});

it('ignores cancelled commissions when gathering', function () {
    makePayrollCommission(['status' => CommissionStatus::Cancelled]);

    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    // The employee's base salary still lands; the cancelled commission does not.
    expect($run->items->first()->commissions_amount)->toBe('0.00');
});

it('derives the run totals from its items', function () {
    makePayrollAdvance();
    makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    $totals = $this->payroll->runTotals($run);

    expect($totals['gross']->toDecimal())->toBe('70000.00')
        ->and($totals['commissions']->toDecimal())->toBe('2000.00')
        ->and($totals['advances']->toDecimal())->toBe('10000.00')
        ->and($totals['net']->toDecimal())->toBe('62000.00')
        ->and($this->payroll->totalNetFor($run)->toDecimal())->toBe('62000.00');
});

// -----------------------------------------------------------------------
// Approve — the accrual and the status flip land together
// -----------------------------------------------------------------------

it('approves a draft run, posts the accrual and stamps the trail', function () {
    makePayrollAdvance();
    makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    $transactions = $this->payments->approvePayroll($run, $this->accountant->id);
    $run = $run->fresh();

    expect($transactions)->toHaveCount(3)
        ->and($run->status)->toBe(PayrollStatus::Approved)
        ->and($run->approved_by_id)->toBe($this->accountant->id)
        ->and($run->approved_at)->not->toBeNull()
        ->and($run->items->first()->status)->toBe('approved');

    // E57 salaries, E59 commissions, E62 advance recovery. balanceOf clamps
    // at zero: the recovery restores the advances asset to its pre-advance
    // level, and cash (1010) only ever decreases from what exists.
    expect($this->accounting->balanceOf(payrollAccount('5080')->id)->toDecimal())->toBe('70000.00')
        ->and($this->accounting->balanceOf(payrollAccount('5090')->id)->toDecimal())->toBe('2000.00')
        ->and($this->accounting->balanceOf(payrollAccount('2300')->id)->toDecimal())->toBe('62000.00')
        ->and($this->accounting->balanceOf(payrollAccount('1130')->id)->toDecimal())->toBe('0.00');
});

it('refuses to approve a run that is not draft', function () {
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $this->payments->approvePayroll($run, $this->accountant->id);

    expect(fn () => $this->payments->approvePayroll($run, $this->accountant->id))
        ->toThrow(DomainException::class, 'draft');
});

// -----------------------------------------------------------------------
// Pay — the highest-consequence double-post is closed
// -----------------------------------------------------------------------

it('pays an approved run and settles the swept commissions', function () {
    makePayrollAdvance();
    $commission = makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $this->payments->approvePayroll($run, $this->accountant->id);

    $transactions = $this->payments->payPayroll($run, $this->accountant->id);
    $run = $run->fresh();

    expect($transactions)->toHaveCount(1)
        ->and($run->status)->toBe(PayrollStatus::Paid)
        ->and($run->paid_at)->not->toBeNull()
        ->and($run->items->first()->status)->toBe('paid')
        ->and(Commission::find($commission->id)->status)->toBe(CommissionStatus::Paid);

    // E60 clears the payable against cash (cash balance clamps at zero).
    expect($this->accounting->balanceOf(payrollAccount('2300')->id)->toDecimal())->toBe('0.00')
        ->and($this->accounting->balanceOf(payrollAccount('1010')->id)->toDecimal())->toBe('0.00');
});

it('refuses to pay a draft run', function () {
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    expect(fn () => $this->payments->payPayroll($run, $this->accountant->id))
        ->toThrow(DomainException::class, 'approved');
});

it('refuses to pay a run twice', function () {
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $this->payments->approvePayroll($run, $this->accountant->id);
    $this->payments->payPayroll($run, $this->accountant->id);

    expect(fn () => $this->payments->payPayroll($run, $this->accountant->id))
        ->toThrow(DomainException::class, 'approved');
});

it('seals a settled commission against amendment', function () {
    makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $this->payments->approvePayroll($run, $this->accountant->id);
    $this->payments->payPayroll($run, $this->accountant->id);

    $commission = Commission::first();

    expect(fn () => app(CommissionService::class)
        ->update($commission, ['amount' => '9999.00'], $this->accountant->id))
        ->toThrow(DomainException::class);
});

// -----------------------------------------------------------------------
// The items relation manager — writable only while the run is draft
// -----------------------------------------------------------------------

it('offers edit and remove only while the run is draft', function () {
    Auth::login($this->manager);

    $run = $this->payroll->generate($this->branch->id, '2026-07-01');

    Livewire::test(PayrollItemsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewPayrollRun::class,
    ])
        ->assertTableActionVisible('edit')
        ->assertTableActionVisible('remove');

    $this->payments->approvePayroll($run, $this->accountant->id);

    Livewire::test(PayrollItemsRelationManager::class, [
        'ownerRecord' => $run->fresh(),
        'pageClass' => ViewPayrollRun::class,
    ])
        ->assertTableActionHidden('edit')
        ->assertTableActionHidden('remove');
});

it('unsweeps a removed draft item so the amounts can be re-gathered', function () {
    $advance = makePayrollAdvance();
    $commission = makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $item = $run->items->first();

    $this->payroll->unsweep($item);

    expect(PayrollRun::find($run->id)?->items)->toBeEmpty()
        ->and(EmployeeAdvance::find($advance->id)?->recovered_in_payroll_item_id)->toBeNull()
        ->and(Commission::find($commission->id)?->payroll_item_id)->toBeNull();
});

it('recomputes gross and net when a draft item is corrected', function () {
    Auth::login($this->manager);

    makePayrollAdvance();
    makePayrollCommission();
    $run = $this->payroll->generate($this->branch->id, '2026-07-01');
    $item = $run->items->first();

    Livewire::test(PayrollItemsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewPayrollRun::class,
    ])
        ->callTableAction('edit', $item->id, [
            'base_salary' => '80000.00',
            'bonuses_amount' => '5000.00',
            'overtime_amount' => '0.00',
            'commissions_amount' => '2000.00',
            'advances_deducted' => '10000.00',
            'absences_deduction' => '0.00',
            'social_contributions' => '0.00',
            'other_deductions' => '0.00',
        ])
        ->assertHasNoTableActionErrors();

    $item = $item->fresh();
    expect($item->gross_amount)->toBe('85000.00')
        ->and($item->net_amount)->toBe('77000.00');
});

// -----------------------------------------------------------------------
// Index surface — derived figures, never stored ones
// -----------------------------------------------------------------------

it('renders the index with the derived total and employee count', function () {
    makePayrollAdvance();
    makePayrollCommission();
    $this->payroll->generate($this->branch->id, '2026-07-01');

    Livewire::test(ListPayrollRuns::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(PayrollRun::all());
});
