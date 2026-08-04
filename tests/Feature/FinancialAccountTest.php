<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\CreateFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\EditFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\ViewFinancialAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\CashRegisterService;
use App\Services\FinancialAccountService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(FinancialAccountSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);
    Auth::login($this->admin);

    $this->account = FinancialAccount::where('is_default_for_cash', true)->firstOrFail();
    $this->service = app(AccountingService::class);
});

// -----------------------------------------------------------------------
// Access
// -----------------------------------------------------------------------

it('canAccess returns true for a user with reports.view_financials', function () {
    expect(FinancialAccountResource::canAccess())->toBeTrue();
});

it('canAccess returns false for a user without reports.view_financials', function () {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Receptionist->value);
    Auth::login($user);

    expect(FinancialAccountResource::canAccess())->toBeFalse();
});

it('the list page renders for an authorised user', function () {
    $this->get(FinancialAccountResource::getUrl('index'))
        ->assertSuccessful();
});

it('the view page renders for an authorised user', function () {
    $this->get(FinancialAccountResource::getUrl('view', [
        'record' => $this->account,
    ]))->assertSuccessful();
});

it('the edit page renders for an authorised user', function () {
    $this->get(FinancialAccountResource::getUrl('edit', [
        'record' => $this->account,
    ]))->assertSuccessful();
});

it('blocks access to the list page for a user without reports.view_financials', function () {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Receptionist->value);
    Auth::login($user);

    $this->get(FinancialAccountResource::getUrl('index'))
        ->assertForbidden();
});

// -----------------------------------------------------------------------
// Delete guard
// -----------------------------------------------------------------------

it('canDelete returns true for an account with no postings', function () {
    expect(FinancialAccountResource::canDelete($this->account))->toBeTrue();
});

it('canDelete returns false for an account with postings', function () {
    $this->service->post(new TransactionDraft(
        debitAccountId: $this->account->ledger_account_id,
        creditAccountId: ChartOfAccount::where('code', '4010')->firstOrFail()->id,
        amount: '100.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Test posting',
        createdById: $this->admin->id,
    ));

    expect(FinancialAccountResource::canDelete($this->account))->toBeFalse();
});

// -----------------------------------------------------------------------
// hasPostings
// -----------------------------------------------------------------------

it('hasPostings returns false when no transactions reference the ledger account', function () {
    expect($this->account->hasPostings())->toBeFalse();
});

it('hasPostings returns true when a transaction debits the linked ledger account', function () {
    $this->service->post(new TransactionDraft(
        debitAccountId: $this->account->ledger_account_id,
        creditAccountId: ChartOfAccount::where('code', '4010')->firstOrFail()->id,
        amount: '100.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Test debit posting',
        createdById: $this->admin->id,
    ));

    expect($this->account->hasPostings())->toBeTrue();
});

it('hasPostings returns true when a transaction credits the linked ledger account', function () {
    $this->service->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '4010')->firstOrFail()->id,
        creditAccountId: $this->account->ledger_account_id,
        amount: '100.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Test credit posting',
        createdById: $this->admin->id,
    ));

    expect($this->account->hasPostings())->toBeTrue();
});

// -----------------------------------------------------------------------
// scopeWithCurrentBalance returns model columns alongside the derived one
// -----------------------------------------------------------------------

it('scopeWithCurrentBalance returns all model columns', function () {
    $results = FinancialAccount::query()->withCurrentBalance()->get();

    expect($results->first())->name->not->toBeNull()
        ->and($results->first()->current_balance)->not->toBeNull();
});

// -----------------------------------------------------------------------
// balancesBatch
// -----------------------------------------------------------------------

it('balancesBatch returns zero for accounts with no transaction activity', function () {
    $service = app(CashRegisterService::class);
    $accounts = FinancialAccount::all();
    $balances = $service->balancesBatch($accounts);

    foreach ($balances as $id => $balance) {
        expect($balance)->toBe('0.00');
    }
});

it('balancesBatch reflects ledger movements', function () {
    $revenueAccount = ChartOfAccount::where('code', '4010')->firstOrFail();

    $this->service->post(new TransactionDraft(
        debitAccountId: $this->account->ledger_account_id,
        creditAccountId: $revenueAccount->id,
        amount: '1500.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Inbound',
        createdById: $this->admin->id,
    ));

    $this->service->post(new TransactionDraft(
        debitAccountId: $revenueAccount->id,
        creditAccountId: $this->account->ledger_account_id,
        amount: '500.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Outbound',
        createdById: $this->admin->id,
    ));

    $service = app(CashRegisterService::class);
    $balances = $service->balancesBatch(collect([$this->account]));

    // Asset account (debit-normal) balance = 1500 - 500 = 1000
    expect($balances[$this->account->id])->toBe('1000.00');
});

// -----------------------------------------------------------------------
// FinancialAccountService::makeDefaultForCash — single default for cash
// -----------------------------------------------------------------------

it('promotes an account to default for cash and demotes the previous holder', function () {
    $other = FinancialAccount::factory()->create();

    app(FinancialAccountService::class)->makeDefaultForCash($other, $this->admin);

    expect(FinancialAccount::where('is_default_for_cash', true)->count())->toBe(1)
        ->and($other->fresh()->is_default_for_cash)->toBeTrue()
        ->and($this->account->fresh()->is_default_for_cash)->toBeFalse();
});

it('is a no-op when the account already holds the default flag', function () {
    app(FinancialAccountService::class)->makeDefaultForCash($this->account, $this->admin);

    expect($this->account->fresh()->is_default_for_cash)->toBeTrue();
});

it('refuses to make an inactive account the default for cash', function () {
    $inactive = FinancialAccount::factory()->create(['is_active' => false]);

    expect(fn () => app(FinancialAccountService::class)->makeDefaultForCash($inactive, $this->admin))
        ->toThrow(DomainException::class)
        ->and($this->account->fresh()->is_default_for_cash)->toBeTrue();
});

it('refuses to promote a default without a staff actor', function () {
    $other = FinancialAccount::factory()->create();

    expect(fn () => app(FinancialAccountService::class)->makeDefaultForCash($other, null))
        ->toThrow(DomainException::class);
});

it('rejects a second row holding is_default_for_cash at the database level', function () {
    $other = FinancialAccount::factory()->create();

    expect(fn () => DB::table('financial_accounts')
        ->where('id', $other->id)
        ->update(['is_default_for_cash' => true]))
        ->toThrow(QueryException::class);
});

it('promotes the default from the create form', function () {
    $ledgerAccount = ChartOfAccount::factory()->cashEquivalent()->create();

    Livewire::test(CreateFinancialAccount::class)
        ->fillForm([
            'ledger_account_id' => $ledgerAccount->id,
            'name' => 'Safe',
            'type' => 'cash_box',
            'opened_on' => now()->toDateString(),
            'is_default_for_cash' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(FinancialAccount::where('is_default_for_cash', true)->count())->toBe(1)
        ->and($this->account->fresh()->is_default_for_cash)->toBeFalse();
});

it('promotes the default from the edit form', function () {
    $other = FinancialAccount::factory()->create();

    Livewire::test(EditFinancialAccount::class, ['record' => $other->getKey()])
        ->fillForm(['is_default_for_cash' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FinancialAccount::where('is_default_for_cash', true)->count())->toBe(1)
        ->and($other->fresh()->is_default_for_cash)->toBeTrue()
        ->and($this->account->fresh()->is_default_for_cash)->toBeFalse();
});

// -----------------------------------------------------------------------
// ledger_account_id restricted to cash-equivalent, postable accounts
// -----------------------------------------------------------------------

it('excludes non-cash-equivalent accounts from the ledger account options', function () {
    $revenueAccount = ChartOfAccount::where('code', '4010')->firstOrFail();

    Livewire::test(CreateFinancialAccount::class)
        ->assertFormFieldExists('ledger_account_id', function (Select $component) use ($revenueAccount): bool {
            $options = $component->getOptions();

            return ! array_key_exists($revenueAccount->id, $options);
        });
});

// -----------------------------------------------------------------------
// makeDefaultForCash failures surface as a friendly message, not a crash
// -----------------------------------------------------------------------

it('shows a field error instead of crashing when editing tries to default an inactive account', function () {
    $inactive = FinancialAccount::factory()->create(['is_active' => false]);

    Livewire::test(EditFinancialAccount::class, ['record' => $inactive->getKey()])
        ->fillForm(['is_default_for_cash' => true])
        ->call('save')
        ->assertHasFormErrors(['is_default_for_cash']);

    expect($inactive->fresh()->is_default_for_cash)->toBeFalse();
});

it('creates the account and notifies instead of crashing when it cannot also be made default', function () {
    $ledgerAccount = ChartOfAccount::factory()->cashEquivalent()->create();

    Livewire::test(CreateFinancialAccount::class)
        ->fillForm([
            'ledger_account_id' => $ledgerAccount->id,
            'name' => 'Dormant Safe',
            'type' => 'cash_box',
            'opened_on' => now()->toDateString(),
            'is_default_for_cash' => true,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $account = FinancialAccount::where('name', 'Dormant Safe')->firstOrFail();

    expect($account->is_default_for_cash)->toBeFalse()
        ->and($this->account->fresh()->is_default_for_cash)->toBeTrue();
});

// -----------------------------------------------------------------------
// CashRegisterService::injectCapital() / recordDrawing() — E70/E71
// -----------------------------------------------------------------------

it('posts a capital injection as Dr the account, Cr Owner Capital', function () {
    $capital = ChartOfAccount::where('code', '3000')->firstOrFail();

    $transaction = app(CashRegisterService::class)->injectCapital(
        $this->account,
        '1850000.00',
        '2026-01-05',
        $this->admin,
    );

    expect($transaction->debit_account_id)->toBe($this->account->ledger_account_id)
        ->and($transaction->credit_account_id)->toBe($capital->id)
        ->and($transaction->amount)->toBe('1850000.00')
        ->and($transaction->type)->toBe(TransactionType::Capital)
        ->and($transaction->occurred_on->toDateString())->toBe('2026-01-05');

    expect(app(CashRegisterService::class)->balanceOf($this->account)->toDecimal())->toBe('1850000.00');
});

it('posts an owner drawing as Dr Drawings, Cr the account', function () {
    app(CashRegisterService::class)->injectCapital($this->account, '500000.00', '2026-01-01', $this->admin);

    $drawings = ChartOfAccount::where('code', '3200')->firstOrFail();

    $transaction = app(CashRegisterService::class)->recordDrawing(
        $this->account,
        '75000.00',
        '2026-01-10',
        $this->admin,
    );

    expect($transaction->debit_account_id)->toBe($drawings->id)
        ->and($transaction->credit_account_id)->toBe($this->account->ledger_account_id)
        ->and($transaction->type)->toBe(TransactionType::Drawings);

    expect(app(CashRegisterService::class)->balanceOf($this->account)->toDecimal())->toBe('425000.00');
});

it('refuses a non-positive capital injection', function () {
    expect(fn () => app(CashRegisterService::class)->injectCapital($this->account, '0.00', '2026-01-01', $this->admin))
        ->toThrow(RuntimeException::class);
});

it('refuses a non-positive owner drawing', function () {
    expect(fn () => app(CashRegisterService::class)->recordDrawing($this->account, '-100.00', '2026-01-01', $this->admin))
        ->toThrow(RuntimeException::class);
});

// -----------------------------------------------------------------------
// The panel actions — gated on finance.manage_capital
// -----------------------------------------------------------------------

it('lets a manager post a capital injection from the view page', function () {
    Livewire::test(ViewFinancialAccount::class, ['record' => $this->account->getRouteKey()])
        ->callAction('inject_capital', [
            'amount' => '1850000',
            'occurred_on' => '2026-01-05',
            'description' => 'Opening capital',
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $transaction = Transaction::where('type', TransactionType::Capital)->firstOrFail();

    expect($transaction->amount)->toBe('1850000.00')
        ->and($transaction->description)->toBe('Opening capital')
        ->and($transaction->created_by_id)->toBe($this->admin->id);
});

it('blocks a receptionist from the view page entirely, so the capital actions are unreachable', function () {
    $receptionist = User::factory()->create(['branch_id' => $this->account->branch_id]);
    $receptionist->assignRole(UserRole::Receptionist->value);
    Auth::login($receptionist);

    $this->get(FinancialAccountResource::getUrl('view', ['record' => $this->account]))
        ->assertForbidden();
});

it('shows both capital actions to an accountant', function () {
    $accountant = User::factory()->create(['branch_id' => $this->account->branch_id]);
    $accountant->assignRole(UserRole::Accountant->value);
    Auth::login($accountant);

    Livewire::test(ViewFinancialAccount::class, ['record' => $this->account->getRouteKey()])
        ->assertActionVisible('inject_capital')
        ->assertActionVisible('record_drawing');
});
