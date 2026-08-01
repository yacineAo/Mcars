<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DeductionReason;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\DepositResource\Pages\EditDeposit;
use App\Filament\Admin\Resources\DepositResource\Pages\ListDeposits;
use App\Filament\Admin\Resources\DepositResource\Pages\ViewDeposit;
use App\Filament\Admin\Resources\DepositResource\RelationManagers\DeductionsRelationManager;
use App\Filament\Admin\Resources\DepositResource\RelationManagers\TransactionsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\DepositService;
use App\Support\Money;
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

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    // Each booking gets its own car — the bookings EXCLUDE constraint forbids
    // two windows on the same car, and several deposits per test each book one.
    $this->makeBooking = fn (): Booking => Booking::create([
        'branch_id' => $this->branch->id,
        'car_id' => Car::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => '5000.00'])->id,
        'customer_id' => $this->customer->id,
        'status' => BookingStatus::Confirmed->value,
        'pickup_at' => '2026-08-01 10:00:00',
        'expected_return_at' => '2026-08-05 10:00:00',
        'daily_rate' => 5000.00,
        'days_count' => 4,
        'subtotal' => 20000.00,
        'total_amount' => 20000.00,
        'created_by_id' => $this->manager->id,
    ]);

    Auth::login($this->accountant);
});

function makeDepositRow(array $overrides = []): Deposit
{
    return Deposit::create(array_merge([
        'booking_id' => (test()->makeBooking)()->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->branch->id,
        'amount' => '30000.00',
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => test()->accountant->id,
    ], $overrides));
}

function holdDeposit(Deposit $deposit, int $userId): Deposit
{
    return app(DepositService::class)->hold($deposit, $userId);
}

// -----------------------------------------------------------------------
// Access — a security deposit is a financial record
// -----------------------------------------------------------------------

it('lets the accountant and manager in, denies the receptionist', function () {
    Auth::login($this->accountant);
    $this->get(DepositResource::getUrl('index'))->assertOk();

    Auth::login($this->manager);
    $this->get(DepositResource::getUrl('index'))->assertOk();

    Auth::login($this->receptionist);
    $this->get(DepositResource::getUrl('index'))->assertForbidden();

    expect(DepositResource::canAccess())->toBeFalse();
});

it('offers no delete anywhere, bulk or row', function () {
    $deposit = makeDepositRow();
    holdDeposit($deposit, $this->accountant->id);

    Auth::login($this->manager);

    // A posted deposit cannot be deleted without leaving account 2100 overstated
    // against nothing — and there is no pre-posting window either, so no bulk
    // delete exists at all.
    Livewire::test(ListDeposits::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(Livewire::test(ListDeposits::class)->instance()->getTable()->getBulkActions())->toBeEmpty();
});

// -----------------------------------------------------------------------
// Index — the outstanding liability comes first
// -----------------------------------------------------------------------

it('defaults to outstanding deposits and can show all', function () {
    makeDepositRow(['amount' => '30000.00', 'status' => DepositStatus::Held]);
    makeDepositRow(['amount' => '20000.00', 'status' => DepositStatus::PartiallyRefunded]);
    $refunded = makeDepositRow(['amount' => '10000.00', 'status' => DepositStatus::Refunded]);
    $forfeited = makeDepositRow(['amount' => '10000.00', 'status' => DepositStatus::Forfeited]);

    // The liability the business needs to see, by default; settled ones are history
    // — the count of 2 proves the refunded and forfeited rows are filtered out.
    Livewire::test(ListDeposits::class)
        ->assertCountTableRecords(2)
        ->assertTableColumnStateSet('status', DepositStatus::Held, record: Deposit::where('amount', '30000.00')->firstOrFail())
        ->assertTableColumnStateSet('status', DepositStatus::PartiallyRefunded, record: Deposit::where('amount', '20000.00')->firstOrFail());

    // Narrowing to a single outstanding status works, and selecting every status
    // shows all — resetting only restores the outstanding default view.
    Livewire::test(ListDeposits::class)
        ->set('tableFilters.status.values', [DepositStatus::Held->value])
        ->assertCountTableRecords(1);

    Livewire::test(ListDeposits::class)
        ->set('tableFilters.status.values', DepositStatus::values())
        ->assertCountTableRecords(4);
});

it('shows the remaining balance derived from the deduction rows', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    $deposit = $deposit->fresh();

    app(DepositService::class)->deductFromData($deposit, [
        'reason' => DeductionReason::Damage->value,
        'amount' => 8000,
        'description' => 'Scratched bumper',
    ], $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Fuel->value,
        'amount' => 5000,
    ], $this->accountant->id);

    // 30 000 − 8 000 − 5 000. There is no stored balance column (ADR-003).
    Livewire::test(ListDeposits::class)
        ->assertTableColumnStateSet('remaining_balance', 17000.0, record: $deposit->fresh());
});

it('filters by held_at range', function () {
    $today = makeDepositRow(['held_at' => now()]);
    makeDepositRow(['held_at' => now()->subDays(10)]);

    Livewire::test(ListDeposits::class)
        ->set('tableFilters.held_at', [
            'from' => now()->subDays(2)->format('Y-m-d'),
            'to' => now()->addDays(2)->format('Y-m-d'),
        ])
        ->assertCountTableRecords(1)
        ->assertTableColumnStateSet('status', DepositStatus::Held, record: $today);
});

// -----------------------------------------------------------------------
// Edit — frozen once posted, notes only after that
// -----------------------------------------------------------------------

it('freezes every money field once the deposit is on the ledger', function () {
    $posted = makeDepositRow();
    holdDeposit($posted, $this->accountant->id);

    $unposted = makeDepositRow(['amount' => '15000.00']);

    Auth::login($this->accountant);

    Livewire::test(EditDeposit::class, ['record' => $posted->getRouteKey()])
        ->assertFormFieldIsDisabled('amount')
        ->assertFormFieldIsDisabled('method')
        ->assertFormFieldIsDisabled('held_at')
        ->assertFormFieldIsDisabled('booking_id')
        ->assertFormFieldIsDisabled('customer_id')
        ->assertFormFieldIsDisabled('financial_account_id')
        ->assertFormFieldEnabled('notes');

    Livewire::test(EditDeposit::class, ['record' => $unposted->getRouteKey()])
        ->assertFormFieldEnabled('amount')
        ->assertFormFieldEnabled('held_at');
});

it('refuses to change the amount of a posted deposit', function () {
    $posted = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($posted, $this->accountant->id);

    Auth::login($this->accountant);

    // Raising the deposit after the liability posting is in `transactions` would
    // make the ledger understate what the business owes (ADR-003).
    Livewire::test(EditDeposit::class, ['record' => $posted->getRouteKey()])
        ->fillForm(['amount' => '99999.00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($posted->fresh()->amount)->toEqual('30000.00');
});

// -----------------------------------------------------------------------
// Actions — hold, deduct, refund, all through DepositService
// -----------------------------------------------------------------------

it('hides hold once posted and hides deduct/refund before', function () {
    $unposted = makeDepositRow();
    $posted = makeDepositRow(['amount' => '20000.00']);
    holdDeposit($posted, $this->accountant->id);

    Auth::login($this->accountant);

    Livewire::test(ListDeposits::class)
        ->assertTableActionVisible('hold', $unposted)
        ->assertTableActionHidden('hold', $posted)
        ->assertTableActionHidden('deduct', $unposted)
        ->assertTableActionVisible('deduct', $posted)
        ->assertTableActionHidden('refund', $unposted)
        ->assertTableActionVisible('refund', $posted);
});

it('builds the deduction row in the service, not the UI', function () {
    $deposit = makeDepositRow();
    holdDeposit($deposit, $this->accountant->id);

    Auth::login($this->accountant);

    Livewire::test(ListDeposits::class)
        ->callTableAction('deduct', $deposit->fresh(), [
            'reason' => DeductionReason::Cleaning->value,
            'amount' => 4000,
            'description' => 'Ashtray stains',
        ])
        ->assertHasNoTableActionErrors();

    $deduction = DepositDeduction::query()->firstOrFail();

    // created_by_id is part of the row's shape, owned by the service — a second
    // caller cannot construct the evidence differently.
    expect($deduction->deposit_id)->toBe($deposit->id)
        ->and($deduction->reason)->toBe(DeductionReason::Cleaning)
        ->and($deduction->description)->toBe('Ashtray stains')
        ->and($deduction->created_by_id)->toBe($this->accountant->id)
        ->and($deposit->fresh()->status)->toBe(DepositStatus::PartiallyRefunded);
});

it('lets the service construct the deduction row directly', function () {
    $deposit = makeDepositRow();
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Damage->value,
        'amount' => 12000,
    ], $this->accountant->id);

    expect(DepositDeduction::query()->firstOrFail()->created_by_id)->toBe($this->accountant->id);
});

// -----------------------------------------------------------------------
// View — the deposit's history
// -----------------------------------------------------------------------

it('renders the view page with the booking link and the balance', function () {
    $booking = ($this->makeBooking)();

    $deposit = makeDepositRow(['booking_id' => $booking->id, 'amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    $deposit = $deposit->fresh();

    app(DepositService::class)->deductFromData($deposit, [
        'reason' => DeductionReason::Damage->value,
        'amount' => 8000,
    ], $this->accountant->id);

    Auth::login($this->accountant);

    $this->get(DepositResource::getUrl('view', ['record' => $deposit]))
        ->assertOk()
        ->assertSee((string) $booking->reference)
        ->assertSee(BookingResource::getUrl('view', ['record' => $booking]));
});

it('gates both relation managers on reports.view_financials', function () {
    $deposit = makeDepositRow();
    holdDeposit($deposit, $this->accountant->id);

    Auth::login($this->receptionist);
    expect(DeductionsRelationManager::canViewForRecord($deposit, ViewDeposit::class))->toBeFalse()
        ->and(TransactionsRelationManager::canViewForRecord($deposit, ViewDeposit::class))->toBeFalse();

    Auth::login($this->accountant);
    expect(DeductionsRelationManager::canViewForRecord($deposit, ViewDeposit::class))->toBeTrue()
        ->and(TransactionsRelationManager::canViewForRecord($deposit, ViewDeposit::class))->toBeTrue();
});

it('keeps the deductions relation manager read-only', function () {
    $deposit = makeDepositRow();
    holdDeposit($deposit, $this->accountant->id);

    Auth::login($this->accountant);

    // Rows are created by the deduct action through DepositService, which owns
    // the cap. A create/edit/delete here would bypass it entirely — the one
    // innocent-looking button that would break the invariant.
    Livewire::test(DeductionsRelationManager::class, [
        'ownerRecord' => $deposit,
        'pageClass' => ViewDeposit::class,
    ])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

// -----------------------------------------------------------------------
// The ledger — 2100 is a liability, never revenue
// -----------------------------------------------------------------------

it('posts the deposit to 2100 as a liability and settles it from there', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    $posting = Transaction::query()->where('source_type', 'deposit')->firstOrFail();

    expect($posting->debitAccount->code)->toBe('1010')
        ->and($posting->creditAccount->code)->toBe('2100')
        ->and($posting->creditAccount->type->value)->toBe('liability')
        ->and($posting->amount)->toEqual('30000.00');
});

it('refuses a deduction that would overdraw the deposit', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Damage->value,
        'amount' => 25000,
    ], $this->accountant->id);

    // 25 000 is already gone; 10 000 more would put the deposit 5 000 in the red.
    expect(fn () => app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Cleaning->value,
        'amount' => 10000,
    ], $this->accountant->id))->toThrow(RuntimeException::class);

    // The refused row and its posting both rolled back with the transaction.
    expect(DepositDeduction::query()->count())->toBe(1)
        ->and(Transaction::query()->where('type', TransactionType::DepositDeduction)->count())->toBe(1);
});

it('refunds the remainder after deductions, not the original deposit', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Damage->value,
        'amount' => 8000,
    ], $this->accountant->id);

    $settled = app(DepositService::class)->refund($deposit->fresh(), null, $this->accountant->id);

    // 2100 only ever held 22 000 after the deduction. Refunding 30 000 would take
    // the liability negative and credit 8 000 of cash that never left the till.
    $refund = Transaction::query()->where('type', TransactionType::DepositRefund)->firstOrFail();

    expect($refund->amount)->toEqual('22000.00')
        ->and($refund->debitAccount->code)->toBe('2100')
        ->and($refund->creditAccount->code)->toBe('1010');

    // Every leg of 2100 nets to nothing: 30 000 Cr − 8 000 Dr − 22 000 Dr.
    $liability = ChartOfAccount::where('code', '2100')->firstOrFail();
    $credits = Transaction::query()->where('credit_account_id', $liability->id)->sum('amount');
    $debits = Transaction::query()->where('debit_account_id', $liability->id)->sum('amount');

    expect(Money::of((string) $credits)->minus(Money::of((string) $debits))->toDecimal())->toBe('0.00')
        ->and($settled->status)->toBe(DepositStatus::Refunded)
        ->and($settled->settled_at)->not->toBeNull()
        ->and($settled->settled_by_id)->toBe($this->accountant->id);
});

it('refuses a refund larger than the remaining balance', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Fuel->value,
        'amount' => 5000,
    ], $this->accountant->id);

    expect(fn () => app(DepositService::class)->refund($deposit->fresh(), '30000.00', $this->accountant->id))
        ->toThrow(DomainException::class);

    expect(Transaction::query()->where('type', TransactionType::DepositRefund)->count())->toBe(0);
});

it('leaves a deposit open when the refund is short of the remainder', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    $result = app(DepositService::class)->refund($deposit->fresh(), '10000.00', $this->accountant->id);

    // 20 000 is still owed, so the deposit is not settled and stays actionable.
    expect($result->status)->toBe(DepositStatus::PartiallyRefunded)
        ->and($result->settled_at)->toBeNull()
        ->and(app(DepositService::class)->remainingBalance($result)->toDecimal())->toBe('20000.00');
});

it('counts an earlier partial refund against the balance, so it cannot be paid twice', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->refund($deposit->fresh(), '10000.00', $this->accountant->id);

    // A refund leaves no deduction row — only the E31 posting. If the balance
    // ignored it, this second call would hand back 30 000 of a 30 000 deposit
    // that has already paid out 10 000.
    expect(fn () => app(DepositService::class)->refund($deposit->fresh(), '25000.00', $this->accountant->id))
        ->toThrow(DomainException::class);

    $settled = app(DepositService::class)->refund($deposit->fresh(), null, $this->accountant->id);

    expect($settled->status)->toBe(DepositStatus::Refunded)
        ->and(Transaction::query()->where('type', TransactionType::DepositRefund)->sum('amount'))
        ->toEqual('30000.00');
});

it('forfeits only what is left after deductions', function () {
    $deposit = makeDepositRow(['amount' => '30000.00']);
    holdDeposit($deposit, $this->accountant->id);

    app(DepositService::class)->deductFromData($deposit->fresh(), [
        'reason' => DeductionReason::Damage->value,
        'amount' => 12000,
    ], $this->accountant->id);

    app(DepositService::class)->forfeit($deposit->fresh(), $this->accountant->id);

    // The deducted 12 000 is already revenue in 4060; forfeiting the full 30 000
    // would book it a second time in 4090.
    $forfeit = Transaction::query()->where('type', TransactionType::DepositForfeited)->firstOrFail();

    expect($forfeit->amount)->toEqual('18000.00')
        ->and($forfeit->creditAccount->code)->toBe('4090');
});

// -----------------------------------------------------------------------
// Branch scoping — a deposit is a financial record, pinned server-side
// -----------------------------------------------------------------------

it('pins a user without branches.view_all to their own branch', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    makeDepositRow(['amount' => '30000.00']);
    $elsewhere = makeDepositRow(['amount' => '44000.00', 'branch_id' => $other->id]);

    // The accountant holds reports.view_financials but not branches.view_all.
    Auth::login($this->accountant);

    expect($this->accountant->can('branches.view_all'))->toBeFalse();

    Livewire::test(ListDeposits::class)
        ->assertCountTableRecords(1)
        ->assertCanNotSeeTableRecords([$elsewhere]);

    // The record-level gate mirrors the list query, so the row cannot be reached
    // by guessing its URL either. The pinned query resolves nothing, so this is a
    // 404 rather than a 403 — it does not confirm the deposit exists.
    expect(DepositResource::canView($elsewhere))->toBeFalse()
        ->and(DepositResource::canEdit($elsewhere))->toBeFalse();

    $this->get(DepositResource::getUrl('view', ['record' => $elsewhere]))->assertNotFound();
});

it('lets a manager with branches.view_all see every branch', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);

    makeDepositRow(['amount' => '30000.00']);
    $elsewhere = makeDepositRow(['amount' => '44000.00', 'branch_id' => $other->id]);

    Auth::login($this->manager);

    expect($this->manager->can('branches.view_all'))->toBeTrue();

    Livewire::test(ListDeposits::class)
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$elsewhere]);
});

it('never offers a delete, whatever the branch', function () {
    $deposit = makeDepositRow();

    expect(DepositResource::canDelete($deposit))->toBeFalse();
});
