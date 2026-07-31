<?php

declare(strict_types=1);

use App\Enums\CashSessionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\CashSessionResource;
use App\Filament\Admin\Resources\CashSessionResource\Pages\CreateCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\EditCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\ListCashSessions;
use App\Filament\Admin\Resources\CashSessionResource\Pages\ViewCashSession;
use App\Filament\Admin\Resources\CashSessionResource\RelationManagers\TransactionsRelationManager;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\CashRegisterService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1010')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->supervisor->assignRole(UserRole::Supervisor->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    Auth::login($this->receptionist);

    $this->service = app(CashRegisterService::class);
});

function openSessionForTest(string $float = '50000.00'): CashSession
{
    return app(CashRegisterService::class)->openSession(
        FinancialAccount::where('is_active', true)->firstOrFail(),
        $float,
        Auth::user(),
    );
}

// -----------------------------------------------------------------------
// Access: operate the till versus see the variance
// -----------------------------------------------------------------------

it('lets a receptionist operate the till', function () {
    Auth::login($this->receptionist);

    $this->get(CashSessionResource::getUrl('index'))->assertOk();
    $this->get(CashSessionResource::getUrl('create'))->assertOk();

    expect(CashSessionResource::canAccess())->toBeTrue()
        ->and(CashSessionResource::canCreate())->toBeTrue();
});

it('gives an accountant audit access but not the drawer', function () {
    Auth::login($this->accountant);

    $this->get(CashSessionResource::getUrl('index'))->assertOk();
    $this->get(CashSessionResource::getUrl('create'))->assertForbidden();

    expect(CashSessionResource::canAccess())->toBeTrue()
        ->and(CashSessionResource::canCreate())->toBeFalse();
});

it('denies a supervisor entirely', function () {
    Auth::login($this->supervisor);

    $this->get(CashSessionResource::getUrl('index'))->assertForbidden();

    expect(CashSessionResource::canAccess())->toBeFalse();
});

it('pins the index to the operator\'s own branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $otherAccount = FinancialAccount::factory()->create([
        'branch_id' => $otherBranch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $this->service->openSession($otherAccount, '90000.00', $this->receptionist);
    openSessionForTest();

    Auth::login($this->receptionist);
    Livewire::test(ListCashSessions::class)
        ->assertCountTableRecords(1);

    Auth::login($this->manager);
    Livewire::test(ListCashSessions::class)
        ->assertCountTableRecords(2);
});

it('denies viewing and editing sessions of another branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $otherAccount = FinancialAccount::factory()->create([
        'branch_id' => $otherBranch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $own = openSessionForTest();
    $foreign = $this->service->openSession($otherAccount, '90000.00', $this->receptionist);

    Auth::login($this->receptionist);

    expect(CashSessionResource::canView($own))->toBeTrue()
        ->and(CashSessionResource::canView($foreign))->toBeFalse()
        ->and(CashSessionResource::canEdit($own))->toBeTrue()
        ->and(CashSessionResource::canEdit($foreign))->toBeFalse();

    $this->get(CashSessionResource::getUrl('view', ['record' => $foreign]))
        ->assertForbidden();
});

it('refuses to open a session on another branch\'s account', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $otherAccount = FinancialAccount::factory()->create([
        'branch_id' => $otherBranch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    Auth::login($this->receptionist);

    Livewire::test(CreateCashSession::class)
        ->fillForm([
            'financial_account_id' => $otherAccount->id,
            'opening_float' => '1000',
        ])
        ->call('create')
        ->assertHasFormErrors(['financial_account_id']);

    expect(CashSession::where('financial_account_id', $otherAccount->id)->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Create — one open session per account, surfaced as a validation message
// -----------------------------------------------------------------------

it('opens a session through the service with a posted float', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateCashSession::class)
        ->fillForm([
            'financial_account_id' => $this->account->id,
            'opening_float' => '30000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $session = CashSession::where('financial_account_id', $this->account->id)->firstOrFail();

    expect($session->status)->toBe(CashSessionStatus::Open)
        ->and($session->opened_by_id)->toBe($this->receptionist->id)
        ->and((float) $session->opening_float)->toBe(30000.0);

    // The float itself is posted to the ledger (E64).
    expect(Transaction::where('cash_session_id', $session->id)->count())->toBe(1);
});

it('refuses a second open session on the same account as a validation error', function () {
    Auth::login($this->receptionist);

    openSessionForTest();

    Livewire::test(CreateCashSession::class)
        ->fillForm([
            'financial_account_id' => $this->account->id,
            'opening_float' => '1000',
        ])
        ->call('create')
        ->assertHasFormErrors(['financial_account_id']);
});

// -----------------------------------------------------------------------
// Index — expected/variance gated, filters
// -----------------------------------------------------------------------

it('shows expected and variance only to financial readers', function () {
    $session = openSessionForTest();
    $this->service->closeSession($session, '50500.00', $this->receptionist);

    Auth::login($this->receptionist);
    Livewire::test(ListCashSessions::class)
        ->assertTableColumnHidden('expected')
        ->assertTableColumnHidden('variance');

    Auth::login($this->accountant);
    Livewire::test(ListCashSessions::class)
        ->assertTableColumnVisible('expected')
        ->assertTableColumnVisible('variance');
});

it('computes expected and variance from the service, not the resource', function () {
    $session = openSessionForTest();

    app(AccountingService::class)->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '1010')->valueOrFail('id'),
        creditAccountId: ChartOfAccount::where('code', '4010')->valueOrFail('id'),
        amount: '30000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Customer payment',
        createdById: $this->receptionist->id,
        cashSessionId: $session->id,
        branchId: $session->branch_id,
    ));

    $reconciliation = $this->service->reconciliation($session);

    expect($reconciliation['expected'])->toBe('80000.00')
        ->and($reconciliation['variance'])->toBeNull();

    $this->service->closeSession($session, '80200.00', $this->receptionist);

    $reconciliation = $this->service->reconciliation($session->fresh());

    expect($reconciliation['expected'])->toBe('80000.00')
        ->and($reconciliation['variance'])->toBe('200.00');
});

it('batches reconciliations with the same arithmetic as the per-session call', function () {
    $first = openSessionForTest();
    $this->service->closeSession($first, '50500.00', $this->receptionist);

    $second = openSessionForTest();
    $this->service->closeSession($second, '50500.00', $this->receptionist);

    $batch = $this->service->reconciliations(collect([$first->fresh(), $second->fresh()]));

    expect($batch[(int) $first->id])->toBe($this->service->reconciliation($first->fresh()))
        ->and($batch[(int) $second->id])->toBe($this->service->reconciliation($second->fresh()));
});

it('defaults the status filter to open sessions', function () {
    openSessionForTest();

    Livewire::test(ListCashSessions::class)
        ->assertCountTableRecords(1);
});

it('filters by date range on opened_at', function () {
    $early = $this->service->openSession($this->account, '1000.00', $this->receptionist);
    $this->service->closeSession($early, '1000.00', $this->receptionist);

    $late = $this->service->openSession($this->account, '2000.00', $this->receptionist);
    $this->service->closeSession($late, '2000.00', $this->receptionist);

    $late->opened_at = $late->opened_at->addDay();
    $late->save();

    Livewire::test(ListCashSessions::class)
        ->set('tableFilters.status', null)
        ->set('tableFilters.opened_at', [
            'from' => $late->opened_at->format('Y-m-d'),
            'to' => $late->opened_at->format('Y-m-d'),
        ])
        ->assertCountTableRecords(1);
});

it('gates the branch filter on branches.view_all', function () {
    Auth::login($this->manager);

    expect(array_keys(Livewire::test(ListCashSessions::class)->instance()->getTable()->getFilters()))
        ->toContain('branch_id');

    Auth::login($this->accountant);

    expect(array_keys(Livewire::test(ListCashSessions::class)->instance()->getTable()->getFilters()))
        ->not->toContain('branch_id');
});

// -----------------------------------------------------------------------
// Edit — notes only, nothing else
// -----------------------------------------------------------------------

it('reduces the edit page to notes and carries no actions', function () {
    $session = openSessionForTest();

    Auth::login($this->receptionist);

    Livewire::test(EditCashSession::class, ['record' => $session->getRouteKey()])
        ->assertFormFieldExists('notes')
        ->assertFormFieldDoesNotExist('financial_account_id')
        ->assertFormFieldDoesNotExist('opening_float')
        ->assertActionDoesNotExist('close')
        ->assertActionDoesNotExist('delete');
});

it('denies the edit page to an accountant', function () {
    $session = openSessionForTest();

    Auth::login($this->accountant);

    $this->get(CashSessionResource::getUrl('edit', ['record' => $session]))
        ->assertForbidden();
});

// -----------------------------------------------------------------------
// View — the close entry point, gated reconciliation and postings
// -----------------------------------------------------------------------

it('closes a session from the view page, saving the closing notes', function () {
    $session = openSessionForTest();

    Auth::login($this->receptionist);

    Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])
        ->callAction('close_session', [
            'counted_amount' => '49700',
            'closing_notes' => 'Counted twice before settling.',
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $session->refresh();

    expect($session->status)->toBe(CashSessionStatus::Disputed)
        ->and($session->closed_by_id)->toBe($this->receptionist->id)
        ->and((string) $session->notes)->toBe('Counted twice before settling.');

    // The variance posts to the ledger (E69 — cash short).
    expect(Transaction::where('type', TransactionType::CashShort)->count())->toBe(1);
});

it('hides the close action once a session is closed', function () {
    $session = openSessionForTest();
    $this->service->closeSession($session, '50000.00', $this->receptionist);

    Auth::login($this->receptionist);

    Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])
        ->assertActionHidden('close_session');
});

it('shows reconciliation only to financial readers', function () {
    $session = openSessionForTest();
    $this->service->closeSession($session, '50200.00', $this->receptionist);

    Auth::login($this->receptionist);
    $this->get(CashSessionResource::getUrl('view', ['record' => $session]))
        ->assertOk()
        ->assertDontSee('Rapprochement');

    Auth::login($this->accountant);
    $response = $this->get(CashSessionResource::getUrl('view', ['record' => $session]))
        ->assertOk()
        ->assertSee('Rapprochement');

    // The counted figure must render its value (50 200,00), not the empty-state
    // em dash — regression for the entry pointing at a non-existent attribute.
    $text = preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($response->getContent())));
    expect($text)->toMatch('/Compté\s*50/');
});

it('gates the postings relation manager on reports.view_financials', function () {
    $session = openSessionForTest();

    Auth::login($this->receptionist);
    expect(TransactionsRelationManager::canViewForRecord($session, ViewCashSession::class))->toBeFalse();

    Auth::login($this->accountant);
    expect(TransactionsRelationManager::canViewForRecord($session, ViewCashSession::class))->toBeTrue();
});

it('denies the view page to a supervisor', function () {
    $session = openSessionForTest();

    Auth::login($this->supervisor);

    $this->get(CashSessionResource::getUrl('view', ['record' => $session]))
        ->assertForbidden();
});
