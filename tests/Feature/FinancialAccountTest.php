<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\CashRegisterService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

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
