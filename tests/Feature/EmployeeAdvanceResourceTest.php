<?php

declare(strict_types=1);

use App\Enums\AdvanceStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeAdvanceResource;
use App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages\CreateEmployeeAdvance;
use App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages\EditEmployeeAdvance;
use App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages\ListEmployeeAdvances;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Payment\PaymentService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAdvanceEmployee(array $overrides = []): Employee
{
    return Employee::create(array_merge([
        'employee_number' => 'EMP-ADV-'.strtoupper(Str::random(6)),
        'branch_id' => test()->branch->id,
        'first_name' => 'Salim',
        'last_name' => 'Benaissa',
        'job_title' => 'Receptionist',
        'department' => 'Operations',
        'hire_date' => '2024-01-15',
        'contract_type' => 'cdi',
        'base_salary' => '45000.00',
        'status' => 'active',
    ], $overrides));
}

function makeAdvance(array $overrides = []): EmployeeAdvance
{
    return EmployeeAdvance::create(array_merge([
        'branch_id' => test()->branch->id,
        'employee_id' => test()->employee->id,
        'amount' => '15000.00',
        'advanced_on' => '2026-07-20',
        'reason' => 'Family',
        'status' => AdvanceStatus::Requested,
    ], $overrides));
}

function advanceRoleUser(Branch $branch, UserRole $role): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->manager = advanceRoleUser($this->branch, UserRole::Manager);
    $this->accountant = advanceRoleUser($this->branch, UserRole::Accountant);
    $this->supervisor = advanceRoleUser($this->branch, UserRole::Supervisor);
    $this->receptionist = advanceRoleUser($this->branch, UserRole::Receptionist);

    $this->employee = makeAdvanceEmployee();

    Auth::login($this->accountant);
});

// -----------------------------------------------------------------------
// Access — hr.view_salary opens the list, hr.manage drives the workflow
// -----------------------------------------------------------------------

it('refuses the resource to roles without the payroll permission', function () {
    Auth::login($this->receptionist);
    $this->get(EmployeeAdvanceResource::getUrl('index', panel: 'admin'))->assertForbidden();

    Auth::login($this->supervisor);
    $this->get(EmployeeAdvanceResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('lets the accountant read but never create or approve', function () {
    $advance = makeAdvance();

    expect(EmployeeAdvanceResource::canAccess())->toBeTrue()
        ->and(EmployeeAdvanceResource::canCreate())->toBeFalse()
        ->and(EmployeeAdvanceResource::canEdit($advance))->toBeFalse()
        ->and(EmployeeAdvanceResource::canDelete($advance))->toBeFalse();

    $this->get(EmployeeAdvanceResource::getUrl('index', panel: 'admin'))->assertSuccessful();

    // The actions exist in the schema but are render-hidden for a reader:
    // the manager's workflow buttons never reach an accountant's screen.
    Livewire::test(ListEmployeeAdvances::class)
        ->assertTableActionExists('approve')
        ->assertTableActionHidden('approve')
        ->assertTableActionHidden('reject');
});

it('lets the manager create and drive the workflow', function () {
    Auth::login($this->manager);

    expect(EmployeeAdvanceResource::canAccess())->toBeTrue()
        ->and(EmployeeAdvanceResource::canCreate())->toBeTrue();

    $this->get(EmployeeAdvanceResource::getUrl('create', panel: 'admin'))->assertSuccessful();
});

it('refuses a crafted create call from the accountant', function () {
    $this->get(EmployeeAdvanceResource::getUrl('create', panel: 'admin'))->assertForbidden();
});

// -----------------------------------------------------------------------
// Creating: an advance starts as a request, and never on your own record
// -----------------------------------------------------------------------

it('creates an advance as a requested request', function () {
    Auth::login($this->manager);

    Livewire::test(CreateEmployeeAdvance::class)
        ->fillForm([
            'employee_id' => $this->employee->id,
            'amount' => '15000.00',
            'advanced_on' => '2026-07-20',
            'reason' => 'Family',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $advance = EmployeeAdvance::where('employee_id', $this->employee->id)->sole();

    expect($advance->status)->toBe(AdvanceStatus::Requested)
        ->and($advance->amount)->toBe('15000.00')
        ->and($advance->branch_id)->toBe($this->branch->id);
});

it('refuses an advance on your own employee record', function () {
    Auth::login($this->manager);

    makeAdvanceEmployee(['user_id' => $this->manager->id]);

    Livewire::test(CreateEmployeeAdvance::class)
        ->fillForm([
            'employee_id' => Employee::where('user_id', $this->manager->id)->sole()->id,
            'amount' => '5000.00',
            'advanced_on' => '2026-07-20',
        ])
        ->call('create')
        ->assertHasFormErrors(['employee_id']);

    expect(EmployeeAdvance::count())->toBe(0);
});

it('refuses a crafted status on create — the workflow owns the lifecycle', function () {
    Auth::login($this->manager);

    Livewire::test(CreateEmployeeAdvance::class)
        ->fillForm([
            'employee_id' => $this->employee->id,
            'amount' => '5000.00',
            'advanced_on' => '2026-07-20',
            'status' => 'recovered',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmployeeAdvance::sole()->status)->toBe(AdvanceStatus::Requested);
});

// -----------------------------------------------------------------------
// Approve = authorise AND pay: E61 posts in the same transaction
// -----------------------------------------------------------------------

it('approves an advance and posts E61 to the ledger', function () {
    $advance = makeAdvance();
    $debit = ChartOfAccount::where('code', '1130')->sole()->id;
    $credit = ChartOfAccount::where('code', '1010')->sole()->id;

    Auth::login($this->manager);

    Livewire::test(ListEmployeeAdvances::class)
        ->callTableAction('approve', $advance)
        ->assertHasNoTableActionErrors();

    expect($advance->fresh()->status)->toBe(AdvanceStatus::Outstanding);

    // E61: Dr 1130 (employee advances asset) / Cr 1010 (cash out).
    $posting = Transaction::sole();
    expect($posting->debit_account_id)->toBe($debit)
        ->and($posting->credit_account_id)->toBe($credit)
        ->and($posting->amount)->toBe('15000.00')
        ->and($posting->employee_id)->toBe($this->employee->id)
        ->and(app(AccountingService::class)->balanceOf($debit)->toDecimal())->toBe('15000.00');
});

it('refuses a second approval — no duplicate posting', function () {
    $advance = makeAdvance(['status' => AdvanceStatus::Outstanding]);

    Auth::login($this->manager);

    expect(fn () => app(PaymentService::class)
        ->approveAdvance($advance, $this->manager->id))
        ->toThrow(DomainException::class);

    // ChartOfAccount has no transactions relation — assert the ledger itself,
    // not a property chain that is always empty.
    expect(Transaction::count())->toBe(0);
});

it('refuses to approve an advance on your own record even when crafted', function () {
    makeAdvanceEmployee(['user_id' => $this->manager->id]);
    $advance = makeAdvance([
        'employee_id' => Employee::where('user_id', $this->manager->id)->sole()->id,
    ]);

    Auth::login($this->manager);

    expect(fn () => app(PaymentService::class)
        ->approveAdvance($advance, $this->manager->id))
        ->toThrow(DomainException::class);

    expect($advance->fresh()->status)->toBe(AdvanceStatus::Requested);
});

it('rejects a requested advance without touching the ledger', function () {
    $advance = makeAdvance();

    Auth::login($this->manager);

    Livewire::test(ListEmployeeAdvances::class)
        ->callTableAction('reject', $advance)
        ->assertHasNoTableActionErrors();

    expect($advance->fresh()->status)->toBe(AdvanceStatus::Rejected);

    $ledger = app(AccountingService::class);
    expect($ledger->balanceOf(ChartOfAccount::where('code', '1130')->sole()->id)->toDecimal())->toBe('0.00');
});

it('refuses to reject an advance that is no longer requested', function () {
    $advance = makeAdvance(['status' => AdvanceStatus::Recovered]);

    Auth::login($this->manager);

    expect(fn () => app(PaymentService::class)
        ->rejectAdvance($advance))
        ->toThrow(DomainException::class);
});

// -----------------------------------------------------------------------
// The list: outstanding by default, recovery shown as a derived column
// -----------------------------------------------------------------------

it('shows only open advances by default — requests and amounts owed', function () {
    $requested = makeAdvance(['amount' => '1000.00']);
    $outstanding = makeAdvance(['amount' => '2000.00', 'status' => AdvanceStatus::Outstanding]);
    makeAdvance(['amount' => '3000.00', 'status' => AdvanceStatus::Recovered]);
    makeAdvance(['amount' => '4000.00', 'status' => AdvanceStatus::Rejected]);

    Auth::login($this->manager);

    Livewire::test(ListEmployeeAdvances::class)
        ->assertCanSeeTableRecords([$requested, $outstanding])
        ->assertCanNotSeeTableRecords(EmployeeAdvance::whereIn('status', [
            AdvanceStatus::Recovered,
            AdvanceStatus::Rejected,
        ])->get());
});

it('shows the recovery stamp as a derived column', function () {
    $advance = makeAdvance(['status' => AdvanceStatus::Outstanding]);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-08-01',
        'status' => 'paid',
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $this->employee->id,
        'base_salary' => '45000.00',
        'advances_deducted' => '15000.00',
        'gross_amount' => '45000.00',
        'net_amount' => '30000.00',
        'status' => 'paid',
    ]);

    $advance->update(['recovered_in_payroll_item_id' => PayrollItem::sole()->id, 'status' => AdvanceStatus::Recovered]);

    Auth::login($this->manager);

    Livewire::test(ListEmployeeAdvances::class)
        ->filterTable('open', 0)
        ->assertTableColumnStateSet(
            'recoveredInPayrollItem.payrollRun.period_month',
            $advance->fresh()->recoveredInPayrollItem->payrollRun->period_month,
            $advance,
        );
});

// -----------------------------------------------------------------------
// Frozen once money moved
// -----------------------------------------------------------------------

it('freezes the amount, employee and date once approved', function () {
    $advance = makeAdvance(['status' => AdvanceStatus::Outstanding]);

    Auth::login($this->manager);

    Livewire::test(EditEmployeeAdvance::class, ['record' => $advance->getKey()])
        ->assertFormFieldDisabled('amount')
        ->assertFormFieldDisabled('employee_id')
        ->assertFormFieldDisabled('advanced_on');
});

it('keeps the status field server-owned and untyped', function () {
    $advance = makeAdvance();

    Auth::login($this->manager);

    Livewire::test(EditEmployeeAdvance::class, ['record' => $advance->getKey()])
        ->fillForm(['status' => 'recovered'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($advance->fresh()->status)->toBe(AdvanceStatus::Requested);
});

// -----------------------------------------------------------------------
// No delete path — money records are not deleted
// -----------------------------------------------------------------------

it('offers no bulk delete and refuses every delete path', function () {
    $advance = makeAdvance();

    Auth::login($this->manager);

    expect(EmployeeAdvanceResource::canDelete($advance))->toBeFalse();

    Livewire::test(ListEmployeeAdvances::class)
        ->assertTableBulkActionDoesNotExist('delete');
});

// -----------------------------------------------------------------------
// Branch scoping
// -----------------------------------------------------------------------

it('pins the list and the record pages to the branches the user can reach', function () {
    $other = Branch::factory()->create(['code' => 'OULED']);
    $mine = makeAdvance();
    $theirs = makeAdvance(['branch_id' => $other->id, 'amount' => '9000.00']);

    // The accountant holds hr.view_salary but not branches.view_all, so the
    // pin is meaningful; the manager would see every branch by design.
    Auth::login($this->accountant);

    Livewire::test(ListEmployeeAdvances::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(EmployeeAdvanceResource::canView($theirs))->toBeFalse()
        ->and(EmployeeAdvanceResource::canEdit($theirs))->toBeFalse();

    $this->get(EmployeeAdvanceResource::getUrl('edit', ['record' => $theirs], panel: 'admin'))->assertNotFound();
});
