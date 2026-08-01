<?php

declare(strict_types=1);

use App\Enums\AgreementStatus;
use App\Enums\InstallmentStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\OwnerInstallmentResource;
use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages\CreateOwnerInstallment;
use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages\EditOwnerInstallment;
use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages\ListOwnerInstallments;
use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages\ViewOwnerInstallment;
use App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers\TransactionsRelationManager;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\ChartOfAccount;
use App\Models\OwnerInstallment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\Payment\OwnerStatementService;
use App\Services\Payment\PaymentService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->owner = CarOwner::factory()->create([
        'branch_id' => $this->branch->id,
        'first_name' => 'Ahmed',
        'last_name' => 'Benali',
    ]);

    $this->car = Car::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => '5000.00']);

    $this->agreement = CarOwnershipAgreement::factory()->create([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'car_owner_id' => $this->owner->id,
        'status' => AgreementStatus::Active,
        'start_date' => '2026-01-01',
        'monthly_rent_amount' => '75000.00',
    ]);

    Auth::login($this->accountant);
});

function makeInstallment(array $overrides = []): OwnerInstallment
{
    return OwnerInstallment::create(array_merge([
        'car_ownership_agreement_id' => test()->agreement->id,
        'car_owner_id' => test()->owner->id,
        'car_id' => test()->car->id,
        'branch_id' => test()->branch->id,
        'sequence_number' => 1,
        'total_installments' => null,
        'period_month' => '2026-07-01',
        'due_date' => '2026-07-05',
        'amount_due' => '75000.00',
        'status' => InstallmentStatus::Pending,
    ], $overrides));
}

function accrue(OwnerInstallment $installment, int $userId): OwnerInstallment
{
    app(PaymentService::class)->accrueOwnerInstallment($installment, $userId);

    return $installment->fresh();
}

// -----------------------------------------------------------------------
// Access — an instalment is a liability to a third party
// -----------------------------------------------------------------------

it('lets the accountant and manager in, denies the receptionist', function () {
    Auth::login($this->accountant);
    $this->get(OwnerInstallmentResource::getUrl('index'))->assertOk();

    Auth::login($this->manager);
    $this->get(OwnerInstallmentResource::getUrl('index'))->assertOk();

    Auth::login($this->receptionist);
    $this->get(OwnerInstallmentResource::getUrl('index'))->assertForbidden();

    expect(OwnerInstallmentResource::canAccess())->toBeFalse();
});

// -----------------------------------------------------------------------
// Delete — only before accrual, never in bulk
// -----------------------------------------------------------------------

it('offers no bulk delete anywhere — the row delete stays, the sweep is gone', function () {
    makeInstallment();

    Auth::login($this->manager);

    Livewire::test(ListOwnerInstallments::class)
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('deleteMany');

    expect(Livewire::test(ListOwnerInstallments::class)->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('allows a single delete only while the instalment is unaccrued', function () {
    $unaccrued = makeInstallment();
    $accrued = accrue(makeInstallment(['sequence_number' => 2]), $this->accountant->id);

    Auth::login($this->accountant);

    Livewire::test(ListOwnerInstallments::class)
        ->assertTableActionVisible('delete', $unaccrued)
        ->assertTableActionHidden('delete', $accrued);

    expect(OwnerInstallmentResource::canDelete($unaccrued))->toBeTrue()
        ->and(OwnerInstallmentResource::canDelete($accrued))->toBeFalse();

    // Deleting an unposted row is the correction path: no ledger rows exist
    // yet, so nothing is orphaned.
    Livewire::test(ListOwnerInstallments::class)
        ->callTableAction('delete', $unaccrued)
        ->assertHasNoTableActionErrors();

    expect(OwnerInstallment::find($unaccrued->id))->toBeNull();
});

it('refuses a delete on another branch even through a crafted call', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    $elsewhere = makeInstallment(['branch_id' => $other->id]);

    Auth::login($this->accountant);

    // The record is absent from the branch-pinned table, so the action cannot
    // even mount on it server-side; `visible()` and the delete's `disabled()`
    // are the second and third lines of defence. The row survives the call.
    expect(fn () => Livewire::test(ListOwnerInstallments::class)
        ->callTableAction('delete', $elsewhere),
    )->toThrow(ActionNotResolvableException::class);

    expect(OwnerInstallment::find($elsewhere->id))->not->toBeNull();
});

it('refuses an accrue on another branch — no ledger row may be posted', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    $elsewhere = makeInstallment(['branch_id' => $other->id]);

    Auth::login($this->accountant);

    expect(fn () => Livewire::test(ListOwnerInstallments::class)
        ->callTableAction('accrue', $elsewhere),
    )->toThrow(ActionNotResolvableException::class);

    expect(Transaction::query()
        ->where('source_type', 'owner_installment')
        ->where('source_id', $elsewhere->id)
        ->exists())->toBeFalse();
});

// -----------------------------------------------------------------------
// Index — the accountant's queue first
// -----------------------------------------------------------------------

it('shows the accrued indicator derived from the pointer, not the action', function () {
    $unaccrued = makeInstallment();
    $accrued = accrue(makeInstallment([
        'sequence_number' => 2,
        'period_month' => '2026-06-01',
        'due_date' => '2026-06-05',
    ]), $this->accountant->id);

    Livewire::test(ListOwnerInstallments::class)
        ->assertTableColumnFormattedStateSet('accrual_transaction_id', __('owner_installments.fields.not_accrued'), record: $unaccrued)
        ->assertTableColumnFormattedStateSet('accrual_transaction_id', __('owner_installments.fields.accrued'), record: $accrued);
});

it('shows the paid figure derived from the ledger', function () {
    $installment = accrue(makeInstallment(), $this->accountant->id);

    // E34: the owner is paid 30 000 of the 75 000 owed, against this
    // instalment — the paid figure is a SUM over the ledger, never stored.
    app(AccountingService::class)->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '2200')->value('id'),
        creditAccountId: ChartOfAccount::where('code', '1010')->value('id'),
        amount: '30000.00',
        type: TransactionType::OwnerInstallment,
        occurredOn: new DateTimeImmutable('2026-07-10'),
        branchId: $this->branch->id,
        carOwnerId: $this->owner->id,
        carId: $this->car->id,
        sourceType: 'owner_installment',
        sourceId: $installment->id,
    ));

    Livewire::test(ListOwnerInstallments::class)
        ->assertTableColumnStateSet('paid', 30000.0, record: $installment);

    // A payment against a different instalment must not inflate this one.
    $other = accrue(makeInstallment([
        'sequence_number' => 2,
        'period_month' => '2026-06-01',
        'due_date' => '2026-06-05',
    ]), $this->accountant->id);

    expect(app(AccountingService::class)->balanceOf(ChartOfAccount::where('code', '2200')->value('id'))->toDecimal())
        ->toBe('120000.00');
});

it('filters to the unaccrued queue, the reason accrue exists', function () {
    $unaccrued = makeInstallment();
    accrue(makeInstallment([
        'sequence_number' => 2,
        'period_month' => '2026-06-01',
        'due_date' => '2026-06-05',
    ]), $this->accountant->id);

    Livewire::test(ListOwnerInstallments::class)
        ->set('tableFilters.unaccrued', ['isActive' => true])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$unaccrued]);
});

it('filters overdue by due_date, excluding settled rows', function () {
    $overdue = makeInstallment(['due_date' => now()->subDays(3)->format('Y-m-d')]);
    $paidLate = makeInstallment([
        'sequence_number' => 2,
        'due_date' => now()->subDays(10)->format('Y-m-d'),
        'status' => InstallmentStatus::Paid,
    ]);
    $waived = makeInstallment([
        'sequence_number' => 3,
        'due_date' => now()->subDays(5)->format('Y-m-d'),
        'status' => InstallmentStatus::Waived,
    ]);
    makeInstallment([
        'sequence_number' => 4,
        'due_date' => now()->addDays(5)->format('Y-m-d'),
    ]);

    Livewire::test(ListOwnerInstallments::class)
        ->set('tableFilters.overdue', ['isActive' => true])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$paidLate, $waived]);
});

it('filters by period month, owner and car', function () {
    $july = makeInstallment();
    $june = makeInstallment([
        'sequence_number' => 2,
        'period_month' => '2026-06-01',
        'due_date' => '2026-06-05',
    ]);

    Livewire::test(ListOwnerInstallments::class)
        ->set('tableFilters.period_month', ['value' => '2026-06-01'])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$june]);

    Livewire::test(ListOwnerInstallments::class)
        ->set('tableFilters.car_owner_id', ['value' => $this->owner->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$july, $june]);

    Livewire::test(ListOwnerInstallments::class)
        ->set('tableFilters.car_id', ['value' => $this->car->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$july, $june]);
});

// -----------------------------------------------------------------------
// Edit — frozen once accrued, notes and waiver reason after that
// -----------------------------------------------------------------------

it('freezes every money field once the instalment is accrued', function () {
    $accrued = accrue(makeInstallment(), $this->accountant->id);
    $pending = makeInstallment(['sequence_number' => 2]);

    Livewire::test(EditOwnerInstallment::class, ['record' => $accrued->getRouteKey()])
        ->assertFormFieldIsDisabled('car_ownership_agreement_id')
        ->assertFormFieldIsDisabled('car_owner_id')
        ->assertFormFieldIsDisabled('car_id')
        ->assertFormFieldIsDisabled('period_month')
        ->assertFormFieldIsDisabled('due_date')
        ->assertFormFieldIsDisabled('amount_due')
        ->assertFormFieldIsDisabled('status')
        ->assertFormFieldEnabled('notes')
        ->assertFormFieldEnabled('waived_reason');

    Livewire::test(EditOwnerInstallment::class, ['record' => $pending->getRouteKey()])
        ->assertFormFieldEnabled('amount_due')
        ->assertFormFieldEnabled('period_month')
        ->assertFormFieldEnabled('status');
});

it('refuses to change the amount of an accrued instalment', function () {
    $accrued = accrue(makeInstallment(), $this->accountant->id);

    // E32 credited 2200 with 75 000; raising the row would make the payable
    // disagree with the ledger (ADR-003).
    Livewire::test(EditOwnerInstallment::class, ['record' => $accrued->getRouteKey()])
        ->fillForm(['amount_due' => '99999.00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($accrued->fresh()->amount_due)->toEqual('75000.00');
});

// -----------------------------------------------------------------------
// Actions — accrue through PaymentService, once only
// -----------------------------------------------------------------------

it('hides accrue once the instalment is on the ledger', function () {
    $pending = makeInstallment();
    $accrued = accrue(makeInstallment(['sequence_number' => 2]), $this->accountant->id);

    Livewire::test(ListOwnerInstallments::class)
        ->assertTableActionVisible('accrue', $pending)
        ->assertTableActionHidden('accrue', $accrued);
});

it('accrues E32 through PaymentService and stamps the pointer', function () {
    $installment = makeInstallment();

    Livewire::test(ListOwnerInstallments::class)
        ->callTableAction('accrue', $installment)
        ->assertHasNoTableActionErrors();

    $posting = Transaction::query()
        ->where('source_type', 'owner_installment')
        ->where('source_id', $installment->id)
        ->firstOrFail();

    expect($posting->debitAccount->code)->toBe('5010')
        ->and($posting->creditAccount->code)->toBe('2200')
        ->and($posting->amount)->toEqual('75000.00')
        ->and($installment->fresh()->accrual_transaction_id)->toBe($posting->id);
});

// -----------------------------------------------------------------------
// Create — manual rows are corrections, still numbered
// -----------------------------------------------------------------------

it('auto-numbers a manually created instalment inside the agreement run', function () {
    makeInstallment(['sequence_number' => 1, 'total_installments' => null]);

    Livewire::test(CreateOwnerInstallment::class)
        ->fillForm([
            'car_ownership_agreement_id' => $this->agreement->id,
            'car_owner_id' => $this->owner->id,
            'car_id' => $this->car->id,
            'period_month' => '2026-08-01',
            'due_date' => '2026-08-05',
            'amount_due' => '75000.00',
            'status' => InstallmentStatus::Pending->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = OwnerInstallment::where('period_month', '2026-08-01')->firstOrFail();

    expect($created->sequence_number)->toBe(2)
        ->and($created->total_installments)->toBeNull();
});

it('rejects a duplicate sequence inside the same agreement — the race must fail loudly', function () {
    makeInstallment(['sequence_number' => 1]);

    OwnerInstallment::create([
        'branch_id' => $this->branch->id,
        'car_ownership_agreement_id' => $this->agreement->id,
        'car_owner_id' => $this->owner->id,
        'car_id' => $this->car->id,
        'period_month' => '2026-08-01',
        'due_date' => '2026-08-05',
        'amount_due' => '75000.00',
        'sequence_number' => 1,
        'status' => InstallmentStatus::Pending,
        'created_by_id' => $this->accountant->id,
    ]);
})->throws(QueryException::class);

// -----------------------------------------------------------------------
// View — the single-instalment statement
// -----------------------------------------------------------------------

it('renders the view page with the owner and car links', function () {
    $installment = accrue(makeInstallment(), $this->accountant->id);

    Auth::login($this->accountant);

    $this->get(OwnerInstallmentResource::getUrl('view', ['record' => $installment]))
        ->assertOk()
        ->assertSee('Ahmed Benali')
        ->assertSee($this->car->registration_number);
});

it('gates both relation managers on reports.view_financials', function () {
    $installment = makeInstallment();

    Auth::login($this->receptionist);
    expect(TransactionsRelationManager::canViewForRecord($installment, ViewOwnerInstallment::class))->toBeFalse()
        ->and(PaymentsRelationManager::canViewForRecord($installment, ViewOwnerInstallment::class))->toBeFalse();

    Auth::login($this->accountant);
    expect(TransactionsRelationManager::canViewForRecord($installment, ViewOwnerInstallment::class))->toBeTrue()
        ->and(PaymentsRelationManager::canViewForRecord($installment, ViewOwnerInstallment::class))->toBeTrue();
});

it('lists only the payments against this instalment, strictly read-only', function () {
    $installment = accrue(makeInstallment(), $this->accountant->id);

    // 30 000 paid against this instalment...
    app(AccountingService::class)->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '2200')->value('id'),
        creditAccountId: ChartOfAccount::where('code', '1010')->value('id'),
        amount: '30000.00',
        type: TransactionType::OwnerInstallment,
        occurredOn: new DateTimeImmutable('2026-07-10'),
        branchId: $this->branch->id,
        carOwnerId: $this->owner->id,
        carId: $this->car->id,
        sourceType: 'owner_installment',
        sourceId: $installment->id,
    ));

    // ...and 10 000 against another. The relation manager shows only the first,
    // and the E32 accrual itself (a credit of 2200) is not a payment.
    $other = accrue(makeInstallment([
        'sequence_number' => 2,
        'period_month' => '2026-06-01',
        'due_date' => '2026-06-05',
    ]), $this->accountant->id);

    app(AccountingService::class)->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '2200')->value('id'),
        creditAccountId: ChartOfAccount::where('code', '1010')->value('id'),
        amount: '10000.00',
        type: TransactionType::OwnerInstallment,
        occurredOn: new DateTimeImmutable('2026-06-10'),
        branchId: $this->branch->id,
        carOwnerId: $this->owner->id,
        carId: $this->car->id,
        sourceType: 'owner_installment',
        sourceId: $other->id,
    ));

    Auth::login($this->accountant);

    // E36: the instalment is waived — a debit of 2200 that is not a payment.
    // The list must keep showing only the real payment row.
    app(AccountingService::class)->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '2200')->value('id'),
        creditAccountId: ChartOfAccount::where('code', '5010')->value('id'),
        amount: '45000.00',
        type: TransactionType::OwnerInstallment,
        occurredOn: new DateTimeImmutable('2026-07-15'),
        branchId: $this->branch->id,
        carOwnerId: $this->owner->id,
        carId: $this->car->id,
        sourceType: 'owner_installment',
        sourceId: $installment->id,
        meta: ['waived' => 'true'],
    ));

    $payment = Transaction::query()
        ->where('source_type', 'owner_installment')
        ->where('source_id', $installment->id)
        ->where('debit_account_id', ChartOfAccount::where('code', '2200')->value('id'))
        ->where(function ($q): void {
            $q->whereNull('meta->waived')->orWhere('meta->waived', '!=', 'true');
        })
        ->firstOrFail();

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $installment,
        'pageClass' => ViewOwnerInstallment::class,
    ])
        ->assertCountTableRecords(1)
        ->assertTableColumnStateSet('amount', 30000.0, record: $payment)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

// -----------------------------------------------------------------------
// The ledger — E32 credits 2200, the owner payable
// -----------------------------------------------------------------------

it('keeps the payable and the accrual in step on the ledger', function () {
    $installment = accrue(makeInstallment(), $this->accountant->id);

    $rentExpense = app(AccountingService::class)->balanceOf(ChartOfAccount::where('code', '5010')->value('id'));
    $payable = app(AccountingService::class)->balanceOf(ChartOfAccount::where('code', '2200')->value('id'));

    expect($rentExpense->toDecimal())->toBe('75000.00')
        ->and($payable->toDecimal())->toBe('75000.00');
});

// -----------------------------------------------------------------------
// Open-ended totals — no more "3 of 999"
// -----------------------------------------------------------------------

it('stores a null total for an open-ended agreement', function () {
    $count = app(OwnerStatementService::class)->generateMonthlyInstallments(
        Carbon::parse('2026-07-01'),
        $this->accountant->id,
    );

    expect($count)->toBe(1);

    $installment = OwnerInstallment::where('car_owner_id', $this->owner->id)->firstOrFail();

    expect($installment->total_installments)->toBeNull()
        ->and($installment->sequence_number)->toBe(1);
});

it('keeps the agreement total when one is set', function () {
    $this->agreement->update(['installments_count' => 12]);

    app(OwnerStatementService::class)->generateMonthlyInstallments(
        Carbon::parse('2026-07-01'),
        $this->accountant->id,
    );

    expect(OwnerInstallment::where('car_owner_id', $this->owner->id)->firstOrFail()->total_installments)->toBe(12);
});

// -----------------------------------------------------------------------
// Branch scoping — an instalment is a financial record, pinned server-side
// -----------------------------------------------------------------------

it('pins a user without branches.view_all to their own branch', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    makeInstallment();
    $elsewhere = makeInstallment([
        'sequence_number' => 2,
        'branch_id' => $other->id,
    ]);

    // The accountant holds reports.view_financials but not branches.view_all.
    Auth::login($this->accountant);

    expect($this->accountant->can('branches.view_all'))->toBeFalse();

    Livewire::test(ListOwnerInstallments::class)
        ->assertCountTableRecords(1)
        ->assertCanNotSeeTableRecords([$elsewhere]);

    expect(OwnerInstallmentResource::canView($elsewhere))->toBeFalse()
        ->and(OwnerInstallmentResource::canEdit($elsewhere))->toBeFalse();

    $this->get(OwnerInstallmentResource::getUrl('view', ['record' => $elsewhere]))->assertNotFound();
});

it('lets a manager with branches.view_all see every branch', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    makeInstallment();
    $elsewhere = makeInstallment([
        'sequence_number' => 2,
        'branch_id' => $other->id,
    ]);

    Auth::login($this->manager);

    expect($this->manager->can('branches.view_all'))->toBeTrue();

    Livewire::test(ListOwnerInstallments::class)
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$elsewhere]);
});
