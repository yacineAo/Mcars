<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\TransactionResource;
use App\Filament\Admin\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Admin\Resources\TransactionResource\Pages\ViewTransaction;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);
    Auth::login($this->admin);

    $this->service = app(AccountingService::class);
});

// -----------------------------------------------------------------------
// The ledger surface is read-only
// -----------------------------------------------------------------------

it('exposes no create, edit or bulk path', function () {
    expect(array_keys(TransactionResource::getPages()))->toBe(['index', 'view'])
        ->and(fn () => TransactionResource::getUrl('create'))->toThrow(Exception::class)
        ->and(fn () => TransactionResource::getUrl('edit', ['record' => 1]))->toThrow(Exception::class);
});

// -----------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------

it('filters by a date range on occurred_on', function () {
    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $early = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-10'),
        description: 'Early posting',
        createdById: $this->admin->id,
    ));
    $late = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '200.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-20'),
        description: 'Late posting',
        createdById: $this->admin->id,
    ));

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.occurred_on', ['from' => '2026-07-15', 'to' => '2026-07-25'])
        ->assertOk()
        ->assertSee($late->reference)
        ->assertDontSee($early->reference);

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.occurred_on', ['from' => '2026-07-15'])
        ->assertOk()
        ->assertSee($late->reference)
        ->assertDontSee($early->reference);
});

it('the account filter matches either leg', function () {
    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();
    $cash = ChartOfAccount::where('code', '1010')->firstOrFail();

    $t1 = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable,
        description: 'Debits fuel',
        createdById: $this->admin->id,
    ));
    $t2 = $this->service->post(new TransactionDraft(
        debitAccountId: $cash->id,
        creditAccountId: $fuel->id,
        amount: '100.00',
        type: TransactionType::ExpensePayment,
        occurredOn: new DateTimeImmutable,
        description: 'Credits fuel',
        createdById: $this->admin->id,
    ));

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.account_id', ['value' => $fuel->id])
        ->assertOk()
        ->assertSee($t1->reference)
        ->assertSee($t2->reference);

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.account_id', ['value' => $rental->id])
        ->assertOk()
        ->assertSee($t1->reference)
        ->assertDontSee($t2->reference);

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.account_id', ['value' => $cash->id])
        ->assertOk()
        ->assertSee($t2->reference)
        ->assertDontSee($t1->reference);
});

it('filters by transaction type', function () {
    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $t1 = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable,
        description: 'Debits fuel',
        createdById: $this->admin->id,
    ));
    $t2 = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::ExpensePayment,
        occurredOn: new DateTimeImmutable,
        description: 'Credits fuel',
        createdById: $this->admin->id,
    ));

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.type', ['value' => TransactionType::Expense->value])
        ->assertOk()
        ->assertSee($t1->reference)
        ->assertDontSee($t2->reference);

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.type', ['value' => TransactionType::ExpensePayment->value])
        ->assertOk()
        ->assertSee($t2->reference)
        ->assertDontSee($t1->reference);
});

it('the is_reversal filter separates reversals from originals', function () {
    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $original = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable,
        description: 'Unique original marker',
        createdById: $this->admin->id,
    ));
    $reversal = $this->service->reverse($original, 'Wrong account', $this->admin);

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.is_reversal', ['value' => true])
        ->assertOk()
        ->assertSee($reversal->reference)
        ->assertDontSee('Unique original marker');

    Livewire::test(ListTransactions::class)
        ->set('tableFilters.is_reversal', ['value' => false])
        ->assertOk()
        ->assertSee('Unique original marker')
        ->assertDontSee($reversal->reference);
});

it('shows the branch filter only with branches.view_all', function () {
    $accountant = User::factory()->create(['branch_id' => $this->admin->branch_id]);
    $accountant->assignRole(UserRole::Accountant->value);

    expect(array_keys(Livewire::test(ListTransactions::class)->instance()->getTable()->getFilters()))
        ->toContain('branch_id');

    Auth::login($accountant);

    expect(array_keys(Livewire::test(ListTransactions::class)->instance()->getTable()->getFilters()))
        ->not->toContain('branch_id');
});

// -----------------------------------------------------------------------
// View page
// -----------------------------------------------------------------------

it('links the source document and the reversal in both directions on the view page', function () {
    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $original = $this->service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable,
        description: 'Mis-posted expense',
        sourceType: 'expense',
        sourceId: 42,
        createdById: $this->admin->id,
    ));

    Livewire::test(ViewTransaction::class, ['record' => $original->getRouteKey()])
        ->assertOk()
        ->assertSee('Expense #42')
        ->assertSee('Mis-posted expense');

    $reversal = $this->service->reverse($original, 'Wrong account', $this->admin);

    Livewire::test(ViewTransaction::class, ['record' => $original->getRouteKey()])
        ->assertOk()
        ->assertSee($reversal->reference);

    Livewire::test(ViewTransaction::class, ['record' => $reversal->getRouteKey()])
        ->assertOk()
        ->assertSee($original->reference);
});
