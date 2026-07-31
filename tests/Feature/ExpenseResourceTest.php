<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Filament\Admin\Resources\ExpenseResource\RelationManagers\TransactionsRelationManager;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\ExpenseService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->supervisor->assignRole(UserRole::Supervisor->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->cashAccount = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1010')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $this->officeRent = ExpenseCategory::where('slug', 'office-rent')->firstOrFail();
    $this->fuel = ExpenseCategory::where('slug', 'fuel')->firstOrFail();

    Auth::login($this->receptionist);

    $this->service = app(ExpenseService::class);
});

function expenseForTest(array $overrides = []): Expense
{
    $category = ExpenseCategory::where('slug', 'office-rent')->firstOrFail();

    return Expense::create(array_merge([
        'branch_id' => Branch::where('code', 'MAIN')->valueOrFail('id'),
        'expense_category_id' => $category->id,
        'amount' => '5000.00',
        'total_amount' => '5000.00',
        'incurred_on' => now(),
        'description' => 'Office rent for the month',
        'status' => ExpenseStatus::Draft,
    ], $overrides));
}

function approvedExpenseForTest(?User $approver = null): Expense
{
    $approver ??= User::factory()->create(['branch_id' => Branch::where('code', 'MAIN')->valueOrFail('id')]);

    return app(ExpenseService::class)->approve(
        expenseForTest(['status' => ExpenseStatus::PendingApproval]),
        $approver,
    );
}

// -----------------------------------------------------------------------
// Access — record is broad, approve and pay are separate permissions
// -----------------------------------------------------------------------

it('lets a receptionist record expenses but neither approve nor pay', function () {
    $this->get(ExpenseResource::getUrl('index'))->assertOk();
    $this->get(ExpenseResource::getUrl('create'))->assertOk();

    expect(ExpenseResource::canAccess())->toBeTrue()
        ->and(ExpenseResource::canCreate())->toBeTrue();

    $pending = expenseForTest(['status' => ExpenseStatus::PendingApproval]);
    $approved = approvedExpenseForTest($this->manager);

    Livewire::test(ListExpenses::class)
        ->assertTableActionHidden('approve', record: $pending)
        ->removeTableFilters()
        ->assertTableActionHidden('pay', record: $approved);

    $this->get(ExpenseResource::getUrl('view', ['record' => $pending]))->assertOk();
    $this->get(ExpenseResource::getUrl('view', ['record' => $approved]))->assertOk();
});

it('lets an accountant pay but not approve', function () {
    Auth::login($this->accountant);

    $approved = approvedExpenseForTest($this->manager);

    expect(ExpenseResource::canAccess())->toBeTrue()
        ->and(ExpenseResource::canCreate())->toBeTrue();

    Livewire::test(ListExpenses::class)
        ->removeTableFilters()
        ->assertTableActionHidden('approve', record: $approved)
        ->assertTableActionVisible('pay', record: $approved);

    $this->get(ExpenseResource::getUrl('view', ['record' => $approved]))
        ->assertOk()
        ->assertSee('Pay & Post');
});

it('lets a manager approve and pay', function () {
    Auth::login($this->manager);

    $pending = expenseForTest(['status' => ExpenseStatus::PendingApproval]);

    Livewire::test(ListExpenses::class)
        ->assertTableActionVisible('approve', record: $pending);

    $this->get(ExpenseResource::getUrl('view', ['record' => $pending]))
        ->assertOk()
        ->assertSee('Approve');
});

it('denies a supervisor entirely', function () {
    Auth::login($this->supervisor);

    $this->get(ExpenseResource::getUrl('index'))->assertForbidden();

    expect(ExpenseResource::canAccess())->toBeFalse();
});

// -----------------------------------------------------------------------
// Service lifecycle — guards are invariants, not button visibility
// -----------------------------------------------------------------------

it('moves an expense through the whole lifecycle, posting E39 on pay', function () {
    $expense = expenseForTest();

    $this->service->submitForApproval($expense, $this->receptionist);
    expect($expense->fresh()->status)->toBe(ExpenseStatus::PendingApproval);

    $approved = $this->service->approve($expense->fresh(), $this->manager);
    expect($approved->status)->toBe(ExpenseStatus::Approved)
        ->and($approved->approved_by_id)->toBe($this->manager->id);

    $paid = $this->service->pay($approved, PaymentMethod::Cash, $this->cashAccount, $this->accountant);

    expect($paid->status)->toBe(ExpenseStatus::Paid)
        ->and($paid->payment_method)->toBe(PaymentMethod::Cash)
        ->and($paid->financial_account_id)->toBe($this->cashAccount->id)
        ->and($paid->transaction_id)->not->toBeNull();

    // E39: debit the category's expense account, credit the paying account.
    $transaction = $paid->transaction;
    expect($transaction->debit_account_id)->toBe($this->officeRent->ledger_account_id)
        ->and($transaction->credit_account_id)->toBe($this->cashAccount->ledger_account_id)
        ->and((float) $transaction->amount)->toBe(5000.0)
        ->and($transaction->source_type)->toBe('expense')
        ->and($transaction->source_id)->toBe($paid->id);
});

it('refuses to pay an expense that is not approved', function () {
    $expense = expenseForTest();

    expect(fn () => $this->service->pay($expense, PaymentMethod::Cash, $this->cashAccount, $this->accountant))
        ->toThrow(RuntimeException::class, 'Only an approved expense can be paid.');
});

it('refuses to pay an expense twice — exactly one posting', function () {
    $approved = approvedExpenseForTest($this->manager);

    $this->service->pay($approved, PaymentMethod::Cash, $this->cashAccount, $this->accountant);

    expect(fn () => $this->service->pay($approved, PaymentMethod::Cash, $this->cashAccount, $this->accountant))
        ->toThrow(RuntimeException::class, 'Only an approved expense can be paid.');

    expect(Expense::findOrFail($approved->id)->ledgerTransactions()->count())->toBe(1);
});

it('refuses to pay from an account of another branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $foreignAccount = FinancialAccount::factory()->create([
        'branch_id' => $otherBranch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $approved = approvedExpenseForTest($this->manager);

    expect(fn () => $this->service->pay($approved, PaymentMethod::Cash, $foreignAccount, $this->accountant))
        ->toThrow(RuntimeException::class, "must belong to the expense's branch");
});

it('rejects an expense with the reason recorded', function () {
    $expense = $this->service->submitForApproval(expenseForTest(), $this->receptionist);

    $rejected = $this->service->reject($expense, 'Missing invoice.', $this->manager);

    expect($rejected->status)->toBe(ExpenseStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Missing invoice.');
});

it('refuses to approve an expense that is already paid', function () {
    $approved = approvedExpenseForTest($this->manager);
    $paid = $this->service->pay($approved, PaymentMethod::Cash, $this->cashAccount, $this->accountant);

    expect(fn () => $this->service->approve($paid, $this->manager))
        ->toThrow(RuntimeException::class);
});

// -----------------------------------------------------------------------
// Create — car_id required exactly when the category is car-related
// -----------------------------------------------------------------------

it('requires a car when the category is car-related', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => $this->fuel->id,
            'amount' => '5000',
            'total_amount' => '5000',
            'incurred_on' => now()->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasFormErrors(['car_id']);
});

it('does not require a car for a non-car category', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => $this->officeRent->id,
            'amount' => '5000',
            'total_amount' => '5000',
            'incurred_on' => now()->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::where('expense_category_id', $this->officeRent->id)->count())->toBe(1);
});

// -----------------------------------------------------------------------
// Edit — frozen once paid, except notes
// -----------------------------------------------------------------------

it('freezes every field except notes once the expense is paid', function () {
    Auth::login($this->receptionist);

    $paid = $this->service->pay(
        approvedExpenseForTest($this->manager),
        PaymentMethod::Cash,
        $this->cashAccount,
        $this->accountant,
    );

    Livewire::test(EditExpense::class, ['record' => $paid->getRouteKey()])
        ->assertFormFieldDisabled('amount')
        ->assertFormFieldDisabled('expense_category_id')
        ->assertFormFieldDisabled('incurred_on')
        ->assertFormFieldEnabled('notes');
});

it('keeps the form editable while the expense is unpaid', function () {
    Auth::login($this->receptionist);

    $expense = expenseForTest();

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertFormFieldEnabled('amount');
});

it('allows deleting an unposted draft but not a paid expense', function () {
    Auth::login($this->receptionist);

    $draft = expenseForTest();

    expect(ExpenseResource::canDelete($draft))->toBeTrue();

    $paid = $this->service->pay(
        approvedExpenseForTest($this->manager),
        PaymentMethod::Cash,
        $this->cashAccount,
        $this->accountant,
    );

    expect(ExpenseResource::canDelete($paid))->toBeFalse();
});

// -----------------------------------------------------------------------
// Index — pending queue by default, branch-pinned
// -----------------------------------------------------------------------

it('defaults the status filter to the pending approval queue', function () {
    expenseForTest();
    expenseForTest(['status' => ExpenseStatus::PendingApproval]);

    Auth::login($this->receptionist);

    Livewire::test(ListExpenses::class)
        ->assertCountTableRecords(1);
});

it('pins the list and record access to the operator\'s branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);

    expenseForTest(['status' => ExpenseStatus::PendingApproval]);
    $foreign = expenseForTest([
        'branch_id' => $otherBranch->id,
        'status' => ExpenseStatus::PendingApproval,
    ]);

    Auth::login($this->receptionist);
    Livewire::test(ListExpenses::class)
        ->assertCountTableRecords(1);

    expect(ExpenseResource::canView($foreign))->toBeFalse();

    Auth::login($this->manager);
    Livewire::test(ListExpenses::class)
        ->assertCountTableRecords(2);
});

// -----------------------------------------------------------------------
// Postings relation manager — read-only, gated, shows reversals
// -----------------------------------------------------------------------

it('gates the postings relation manager on reports.view_financials', function () {
    $expense = expenseForTest();

    Auth::login($this->receptionist);
    expect(TransactionsRelationManager::canViewForRecord($expense, ViewExpense::class))->toBeFalse();

    Auth::login($this->accountant);
    expect(TransactionsRelationManager::canViewForRecord($expense, ViewExpense::class))->toBeTrue();
});

it('lists the posting and its reversal, so a reversed expense cannot look paid', function () {
    $paid = $this->service->pay(
        approvedExpenseForTest($this->manager),
        PaymentMethod::Cash,
        $this->cashAccount,
        $this->accountant,
    );

    expect($paid->ledgerTransactions()->count())->toBe(1);

    $original = $paid->transaction;
    app(AccountingService::class)->reverse($original, 'Wrong amount posted.', $this->accountant);

    expect($paid->fresh()->ledgerTransactions()->count())->toBe(2)
        ->and($paid->ledgerTransactions()->where('is_reversal', true)->count())->toBe(1);
});
