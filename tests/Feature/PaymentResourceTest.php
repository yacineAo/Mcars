<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Filament\Admin\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Admin\Resources\PaymentResource\Pages\EditPayment;
use App\Filament\Admin\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Admin\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Admin\Resources\PaymentResource\RelationManagers\TransactionsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

    $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->supervisor->assignRole(UserRole::Supervisor->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    Auth::login($this->receptionist);
});

function makePaymentRow(array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'reference' => 'PAY-'.strtoupper(Str::random(6)),
        'branch_id' => test()->branch->id,
        'direction' => PaymentDirection::Inbound,
        'customer_id' => test()->customer->id,
        'method' => PaymentMethod::Cash,
        'amount' => '5000.00',
        'paid_at' => now(),
        'status' => PaymentStatus::Completed,
    ], $overrides));
}

function postPayment(Payment $payment, int $userId): void
{
    app(PaymentService::class)->recordPayment($payment, $userId);
}

// -----------------------------------------------------------------------
// Access — the counter clerk records, the accountant audits
// -----------------------------------------------------------------------

it('lets a receptionist and an accountant in, denies a supervisor', function () {
    Auth::login($this->receptionist);
    $this->get(PaymentResource::getUrl('index'))->assertOk();
    $this->get(PaymentResource::getUrl('create'))->assertOk();

    Auth::login($this->accountant);
    $this->get(PaymentResource::getUrl('index'))->assertOk();

    Auth::login($this->supervisor);
    $this->get(PaymentResource::getUrl('index'))->assertForbidden();

    expect(PaymentResource::canAccess())->toBeFalse();
});

it('never deletes a payment, posted or not', function () {
    $unposted = makePaymentRow();
    $posted = makePaymentRow();
    postPayment($posted, $this->receptionist->id);

    Auth::login($this->manager);

    expect(PaymentResource::canDelete($unposted))->toBeFalse()
        ->and(PaymentResource::canDelete($posted))->toBeFalse();
});

it('pins the index to the operator\'s own branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    makePaymentRow();
    makePaymentRow(['branch_id' => $otherBranch->id]);

    Livewire::test(ListPayments::class)
        ->assertCountTableRecords(1);

    Auth::login($this->manager);
    Livewire::test(ListPayments::class)
        ->assertCountTableRecords(2);
});

it('shows nothing to a user with no branch access configured', function () {
    makePaymentRow();
    makePaymentRow(['branch_id' => Branch::factory()->create(['is_default' => false, 'code' => 'OTH'])->id]);

    // No branches.view_all, no pivot rows, no branch_id: accessibleBranchIds() is
    // empty. Skipping the constraint here would widen the query to every branch
    // instead of narrowing it to none, so the guard must fail closed.
    //
    // Nulled through the query builder because BelongsToBranch fills branch_id
    // from the authenticated user on create, so the factory cannot produce this
    // state directly.
    $stray = User::factory()->create();
    $stray->assignRole(UserRole::Receptionist->value);
    User::whereKey($stray->getKey())->update(['branch_id' => null]);

    Auth::login(User::findOrFail($stray->getKey()));

    Livewire::test(ListPayments::class)->assertCountTableRecords(0);

    expect(PaymentResource::canView(Payment::first()))->toBeFalse();
});

it('reaches every branch granted through the branch_user pivot', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $own = makePaymentRow();
    $foreign = makePaymentRow(['branch_id' => $otherBranch->id]);

    // The list query and the record check must agree: a row the table shows is a
    // row the view page opens. Asking users.branch_id here rather than the pivot
    // is what let the two disagree.
    $this->receptionist->branchUsers()->sync([$this->branch->id, $otherBranch->id]);
    Auth::login($this->receptionist->fresh());

    Livewire::test(ListPayments::class)->assertCountTableRecords(2);

    expect(PaymentResource::canView($own))->toBeTrue()
        ->and(PaymentResource::canView($foreign))->toBeTrue();
});

it('denies viewing and editing payments of another branch', function () {
    $otherBranch = Branch::factory()->create(['is_default' => false, 'code' => 'OTH']);
    $own = makePaymentRow();
    $foreign = makePaymentRow(['branch_id' => $otherBranch->id]);

    expect(PaymentResource::canView($own))->toBeTrue()
        ->and(PaymentResource::canView($foreign))->toBeFalse()
        ->and(PaymentResource::canEdit($foreign))->toBeFalse();
});

// -----------------------------------------------------------------------
// Index — the posted indicator, the accountant's queue, the filters
// -----------------------------------------------------------------------

it('shows whether a payment reached the ledger', function () {
    $unposted = makePaymentRow();
    $posted = makePaymentRow();
    postPayment($posted, $this->receptionist->id);

    Livewire::test(ListPayments::class)
        ->assertTableColumnExists('ledger_transactions_exists')
        ->assertTableColumnStateSet('ledger_transactions_exists', false, record: $unposted)
        ->assertTableColumnStateSet('ledger_transactions_exists', true, record: $posted);
});

it('filters to the not-yet-posted queue', function () {
    $unposted = makePaymentRow();
    makePaymentRow();
    postPayment(makePaymentRow(), $this->receptionist->id);

    Livewire::test(ListPayments::class)
        ->assertCountTableRecords(3)
        ->set('tableFilters.unposted', ['isActive' => true])
        ->assertCountTableRecords(2)
        ->assertTableColumnStateSet('ledger_transactions_exists', false, record: $unposted);
});

it('filters by method, direction, status and paid_at range', function () {
    makePaymentRow(['reference' => 'PAY-CASH', 'method' => PaymentMethod::Cash]);
    makePaymentRow(['reference' => 'PAY-TRANSFER', 'method' => PaymentMethod::BankTransfer, 'paid_at' => now()->addDay()]);
    makePaymentRow([
        'reference' => 'PAY-OUT',
        'method' => PaymentMethod::Cheque,
        'direction' => PaymentDirection::Outbound,
        'status' => PaymentStatus::Pending,
    ]);

    Livewire::test(ListPayments::class)
        ->set('tableFilters.method', ['value' => PaymentMethod::Cash->value])
        ->assertCountTableRecords(1);

    Livewire::test(ListPayments::class)
        ->set('tableFilters.direction', ['value' => PaymentDirection::Outbound->value])
        ->assertCountTableRecords(1);

    Livewire::test(ListPayments::class)
        ->set('tableFilters.status', ['value' => PaymentStatus::Pending->value])
        ->assertCountTableRecords(1);

    Livewire::test(ListPayments::class)
        ->set('tableFilters.paid_at', [
            'from' => now()->addDay()->format('Y-m-d'),
            'to' => now()->addDay()->format('Y-m-d'),
        ])
        ->assertCountTableRecords(1);
});

it('gates the branch filter on branches.view_all', function () {
    Auth::login($this->manager);

    expect(array_keys(Livewire::test(ListPayments::class)->instance()->getTable()->getFilters()))
        ->toContain('branch_id');

    Auth::login($this->accountant);

    expect(array_keys(Livewire::test(ListPayments::class)->instance()->getTable()->getFilters()))
        ->not->toContain('branch_id');
});

// -----------------------------------------------------------------------
// Create — posts automatically, method-specific fields
// -----------------------------------------------------------------------

it('posts a payment to the ledger on create', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'reference' => 'PAY-CREATE',
            'direction' => PaymentDirection::Inbound->value,
            'customer_id' => $this->customer->id,
            'method' => PaymentMethod::Cash->value,
            'amount' => '5000.00',
            'paid_at' => now()->format('Y-m-d'),
            'status' => PaymentStatus::Completed->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::where('reference', 'PAY-CREATE')->firstOrFail();

    // Recording and posting are one action — no window where the ledger disagrees.
    expect(Transaction::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1);
});

it('shows method-specific fields only for the chosen method', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'reference' => 'PAY-METHOD',
            'direction' => PaymentDirection::Inbound->value,
            'method' => PaymentMethod::Cash->value,
            'amount' => '5000.00',
            'paid_at' => now()->format('Y-m-d'),
            'status' => PaymentStatus::Completed->value,
        ])
        ->assertFormFieldDoesNotExist('external_reference')
        ->assertFormFieldDoesNotExist('cheque_due_date')
        ->set('data.method', PaymentMethod::Cheque->value)
        ->assertFormFieldExists('external_reference')
        ->assertFormFieldExists('cheque_due_date')
        ->set('data.method', PaymentMethod::BaridiMob->value)
        ->assertFormFieldExists('external_reference')
        ->assertFormFieldDoesNotExist('cheque_due_date');
});

// -----------------------------------------------------------------------
// Edit — frozen once posted, notes only after that
// -----------------------------------------------------------------------

it('freezes the money fields once the payment is on the ledger', function () {
    $posted = makePaymentRow();
    postPayment($posted, $this->receptionist->id);

    $unposted = makePaymentRow();

    Auth::login($this->receptionist);

    Livewire::test(EditPayment::class, ['record' => $posted->getRouteKey()])
        ->assertFormFieldIsDisabled('amount')
        ->assertFormFieldIsDisabled('method')
        ->assertFormFieldIsDisabled('paid_at')
        ->assertFormFieldIsDisabled('direction')
        ->assertFormFieldIsDisabled('status')
        ->assertFormFieldIsDisabled('financial_account_id')
        ->assertFormFieldIsDisabled('customer_id')
        ->assertFormFieldIsDisabled('reference')
        ->assertFormFieldEnabled('notes');

    Livewire::test(EditPayment::class, ['record' => $unposted->getRouteKey()])
        ->assertFormFieldEnabled('amount')
        ->assertFormFieldEnabled('status');
});

it('freezes the method-specific fields once posted', function () {
    $cheque = makePaymentRow([
        'method' => PaymentMethod::Cheque,
        'external_reference' => 'CHQ-001',
        'cheque_due_date' => now()->addDays(7),
    ]);
    postPayment($cheque, $this->receptionist->id);

    Auth::login($this->receptionist);

    Livewire::test(EditPayment::class, ['record' => $cheque->getRouteKey()])
        ->assertFormFieldExists('external_reference')
        ->assertFormFieldIsDisabled('external_reference')
        ->assertFormFieldExists('cheque_due_date')
        ->assertFormFieldIsDisabled('cheque_due_date');
});

it('refuses to change the amount of a posted payment', function () {
    $posted = makePaymentRow(['amount' => '5000.00']);
    postPayment($posted, $this->receptionist->id);

    Auth::login($this->receptionist);

    Livewire::test(EditPayment::class, ['record' => $posted->getRouteKey()])
        ->fillForm(['amount' => '99999.00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($posted->fresh()->amount)->toEqual('5000.00');
});

// -----------------------------------------------------------------------
// post_to_ledger — the retry path for a failed posting
// -----------------------------------------------------------------------

it('posts an unposted payment from the list and hides the action after', function () {
    $payment = makePaymentRow();

    expect(Transaction::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(0);

    Livewire::test(ListPayments::class)
        ->assertTableActionVisible('post_to_ledger', $payment)
        ->callTableAction('post_to_ledger', $payment)
        ->assertHasNoTableActionErrors();

    expect($payment->fresh()->isPostedToLedger())->toBeTrue()
        ->and(Transaction::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1);

    Livewire::test(ListPayments::class)
        ->assertTableActionHidden('post_to_ledger', $payment->fresh());
});

// -----------------------------------------------------------------------
// View — the reconciliation screen
// -----------------------------------------------------------------------

it('renders the view page with the payable link', function () {
    $booking = Booking::create([
        'reference' => 'BK-PAYABLE',
        'branch_id' => $this->branch->id,
        'car_id' => Car::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => '5000.00'])->id,
        'customer_id' => $this->customer->id,
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(2),
        'expected_return_at' => now()->addDays(2),
        'actual_pickup_at' => now()->subDays(2),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'total_amount' => '20000.00',
        'created_by_id' => $this->receptionist->id,
    ]);

    $payment = makePaymentRow([
        'payable_type' => Booking::class,
        'payable_id' => $booking->id,
    ]);
    postPayment($payment, $this->receptionist->id);

    Auth::login($this->accountant);

    // The label alone proves nothing — it renders whether or not the morph
    // resolved to a link. Asserting the target URL is what proves the payable
    // is reachable from the reconciliation screen.
    $this->get(PaymentResource::getUrl('view', ['record' => $payment]))
        ->assertOk()
        ->assertSee('BK-PAYABLE')
        ->assertSee(BookingResource::getUrl('view', ['record' => $booking]));
});

it('gates the postings relation manager on reports.view_financials', function () {
    $payment = makePaymentRow();
    postPayment($payment, $this->receptionist->id);

    Auth::login($this->receptionist);
    expect(TransactionsRelationManager::canViewForRecord($payment, ViewPayment::class))->toBeFalse();

    Auth::login($this->accountant);
    expect(TransactionsRelationManager::canViewForRecord($payment, ViewPayment::class))->toBeTrue();
});
