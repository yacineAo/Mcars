<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Admin\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Fine;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Booking\BookingService;
use App\Services\Payment\PaymentService;
use App\Services\ReportService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    foreach ([
        'admin' => UserRole::SuperAdmin,
        'manager' => UserRole::Manager,
        'receptionist' => UserRole::Receptionist,
        'supervisor' => UserRole::Supervisor,
        'accountant' => UserRole::Accountant,
        'maintenance' => UserRole::MaintenanceOfficer,
    ] as $name => $role) {
        $this->{$name} = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->{$name}->assignRole($role->value);
    }

    $this->car = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
});

/**
 * Each booking gets its own car unless one is named.
 *
 * The `EXCLUDE` constraint is real and must stay that way (ADR-002), so sharing a car
 * across fixtures would make unrelated tests collide on overlapping dates rather than
 * test what they are about. Tests that *want* the collision pass `car_id` explicitly.
 */
function bookingForTest(array $overrides = []): Booking
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();

    return Booking::create(array_merge([
        'branch_id' => $branch->id,
        'car_id' => Car::factory()->create([
            'status' => CarStatus::Available,
            'daily_rate' => '5000.00',
            'branch_id' => $branch->id,
        ])->id,
        'customer_id' => Customer::firstOrFail()->id,
        'created_by_id' => User::firstOrFail()->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->addDay(),
        'expected_return_at' => now()->addDays(5),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'extras_total' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => '20000.00',
        'security_deposit_amount' => '6000.00',
    ], $overrides));
}

/** A booking whose revenue is already on the ledger — the frozen state. */
function checkedOutBooking(User $by): Booking
{
    $booking = bookingForTest(['status' => BookingStatus::Confirmed]);

    return app(BookingService::class)->checkOut($booking, [
        'actual_pickup_at' => now(),
        'odometer_out' => 10_000,
        'fuel_level_out' => 'full',
    ], $by);
}

// -----------------------------------------------------------------------
// Access — bookings.view reads, bookings.operate works the booking
// -----------------------------------------------------------------------

it('lets operating roles work a booking', function (string $role) {
    Auth::login($this->{$role});

    expect(BookingResource::canAccess())->toBeTrue()
        ->and(BookingResource::canOperate())->toBeTrue()
        ->and(BookingResource::canCreate())->toBeTrue();

    $this->get(BookingResource::getUrl('index'))->assertOk();
})->with(['admin', 'manager', 'receptionist', 'supervisor']);

it('lets the accountant read but not operate', function () {
    Auth::login($this->accountant);

    $booking = bookingForTest();

    expect(BookingResource::canAccess())->toBeTrue()
        ->and(BookingResource::canOperate())->toBeFalse()
        ->and(BookingResource::canCreate())->toBeFalse()
        ->and(BookingResource::canEdit($booking))->toBeFalse()
        ->and(BookingResource::canDelete($booking))->toBeFalse();

    $this->get(BookingResource::getUrl('index'))->assertOk();
    $this->get(BookingResource::getUrl('view', ['record' => $booking->getRouteKey()]))->assertOk();
    $this->get(BookingResource::getUrl('create'))->assertForbidden();
});

it('denies the maintenance officer entirely', function () {
    Auth::login($this->maintenance);

    expect(BookingResource::canAccess())->toBeFalse();

    $this->get(BookingResource::getUrl('index'))->assertForbidden();
});

/**
 * A lifecycle action runs in place over Livewire without visiting a page, so the
 * resource has to gate each one itself — Filament's actions carry no authorization.
 */
it('hides every write action from a reader', function () {
    Auth::login($this->accountant);

    $booking = bookingForTest();

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('confirm', record: $booking)
        ->assertTableActionHidden('cancel', record: $booking)
        ->assertTableActionHidden('edit', record: $booking)
        ->assertTableActionHidden('delete', record: $booking);
});

it('refuses to run a lifecycle action for a reader', function () {
    Auth::login($this->accountant);

    $booking = bookingForTest();

    try {
        Livewire::test(ListBookings::class)->callTableAction('confirm', $booking);
    } catch (Throwable) {
        // Refusal by exception is still a refusal.
    }

    expect($booking->fresh()->status)->toBe(BookingStatus::Draft);
});

// -----------------------------------------------------------------------
// Delete — drafts only; anything invoiced is cancelled, not deleted
// -----------------------------------------------------------------------

it('allows deleting a draft', function () {
    Auth::login($this->manager);

    expect(BookingResource::canDelete(bookingForTest()))->toBeTrue();
});

it('refuses to delete a booking that has reached the ledger', function (BookingStatus $status) {
    Auth::login($this->manager);

    $booking = bookingForTest(['status' => $status]);

    expect(BookingResource::canDelete($booking))->toBeFalse();

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('delete', record: $booking);
})->with([
    BookingStatus::Confirmed,
    BookingStatus::Active,
    BookingStatus::Completed,
]);

// -----------------------------------------------------------------------
// Edit — frozen once revenue is posted
// -----------------------------------------------------------------------

it('freezes car, customer, dates and pricing once checked out', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    $page = Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()]);

    foreach (['car_id', 'customer_id', 'pickup_at', 'expected_return_at', 'daily_rate', 'subtotal', 'total_amount'] as $field) {
        $page->assertFormFieldDisabled($field);
    }
});

it('leaves a draft fully editable', function () {
    Auth::login($this->manager);

    $booking = bookingForTest();

    Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->assertFormFieldEnabled('car_id')
        ->assertFormFieldEnabled('total_amount');
});

/**
 * The disabled attribute is a UI property. What matters is that a submitted price is
 * ignored: the ledger rows referencing it are append-only and cannot follow an edit.
 */
it('ignores a submitted price on a checked-out booking', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);
    $originalCarId = $booking->car_id;

    Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->fillForm(['total_amount' => '1.00', 'car_id' => Car::factory()->create()->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($booking->fresh()->total_amount)->toBe('20000.00')
        ->and($booking->fresh()->car_id)->toBe($originalCarId);
});

// -----------------------------------------------------------------------
// record_payment — construction belongs to PaymentService
// -----------------------------------------------------------------------

it('records a payment through the service and posts it', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    Livewire::test(ListBookings::class)
        ->callTableAction('record_payment', $booking, data: [
            'amount' => '5000.00',
            'method' => PaymentMethod::Cash->value,
        ])
        ->assertHasNoTableActionErrors();

    $payment = Payment::where('payable_id', $booking->id)->firstOrFail();

    expect($payment->payable_type)->toBe(Booking::class)
        ->and($payment->customer_id)->toBe($booking->customer_id)
        ->and($payment->branch_id)->toBe($booking->branch_id)
        ->and($payment->received_by_id)->toBe($this->manager->id)
        ->and($payment->isPostedToLedger())->toBeTrue();
});

/**
 * The posting matrix requires the booking dimension on E10–E14. Without it "what is
 * still owed on this booking" cannot be asked of the ledger at all.
 */
it('stamps the booking on the payment ledger row', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '5000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    $row = Transaction::where('source_type', 'payment')->firstOrFail();

    expect($row->booking_id)->toBe($booking->id);
});

// -----------------------------------------------------------------------
// Settlement — derived, never stored
// -----------------------------------------------------------------------

it('derives invoiced, paid and outstanding from the ledger', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 20000.0, 'paid' => 0.0, 'outstanding' => 20000.0]);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '12000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 20000.0, 'paid' => 12000.0, 'outstanding' => 8000.0]);
});

/**
 * The deposit-at-booking flow. Money taken before hand-over has no receivable to clear,
 * so it posts to 2500 (E19) — but it is still the customer's money against this rental.
 * Reporting it as unpaid put "Outstanding 20 000" on the same screen as a 20 000 payment.
 */
it('counts money taken before hand-over as paid on the booking', function () {
    Auth::login($this->manager);

    $booking = bookingForTest(['status' => BookingStatus::Confirmed]);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '20000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    // Nothing invoiced yet, but the money is on account and owed nothing.
    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 0.0, 'paid' => 20000.0, 'outstanding' => -20000.0]);

    app(BookingService::class)->checkOut($booking, [
        'actual_pickup_at' => now(), 'odometer_out' => 10_000, 'fuel_level_out' => 'full',
    ], $this->manager);

    // Invoiced now — and settled, because the prepayment covers it exactly.
    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 20000.0, 'paid' => 20000.0, 'outstanding' => 0.0]);

    expect(app(ReportService::class)->customerStatement($booking->customer_id))
        ->toMatchArray(['paid' => 20000.0, 'owed' => 0.0]);
});

/** The E19 leg must carry the booking, or the credit cannot be attributed to it. */
it('stamps the booking on a payment taken before hand-over', function () {
    Auth::login($this->manager);

    $booking = bookingForTest(['status' => BookingStatus::Confirmed]);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '20000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    $row = Transaction::where('source_type', 'payment')->sole();

    expect($row->creditAccount->code)->toBe('2500')
        ->and($row->booking_id)->toBe($booking->id);
});

/**
 * Applying a credit balance must be a no-op for every derived figure: the receivable
 * clears and the held credit falls by the same amount.
 */
it('leaves the settlement unchanged when a credit is applied to the receivable', function () {
    Auth::login($this->manager);

    $booking = bookingForTest(['status' => BookingStatus::Confirmed]);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '20000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    app(BookingService::class)->checkOut($booking, [
        'actual_pickup_at' => now(), 'odometer_out' => 10_000, 'fuel_level_out' => 'full',
    ], $this->manager);

    $before = app(ReportService::class)->bookingSettlement($booking->id);

    // Dr 2500 / Cr 1110 — the accountant's instrument for applying money on account.
    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '20000.00', 'method' => PaymentMethod::Compensation->value],
        $this->manager->id,
    );

    expect(app(ReportService::class)->bookingSettlement($booking->id))->toBe($before)
        ->and($before)->toMatchArray(['invoiced' => 20000.0, 'paid' => 20000.0, 'outstanding' => 0.0]);
});

/**
 * A traffic fine sits on 1120, not 1110, and the payment's credit leg only ever lands
 * on 1110. If the split measured both control accounts, paying a fine would credit the
 * rental receivable — driving it negative while the fine stayed open on 1120.
 */
it('does not let a fine receivable absorb a payment onto the rental receivable', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    // Settle the rental in full, so 1110 stands at zero.
    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '20000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    $fine = Fine::create([
        'reference' => 'FN-REVIEW-1',
        'branch_id' => $booking->branch_id,
        'booking_id' => $booking->id,
        'car_id' => $booking->car_id,
        'customer_id' => $booking->customer_id,
        'notice_number' => 'FN-REVIEW-1',
        'type' => 'speeding',
        'violation_at' => now()->subDay(),
        'received_at' => now(),
        'amount' => '5000.00',
        'late_penalty_amount' => '0.00',
        'total_amount' => '5000.00',
        'liability' => 'customer',
    ]);

    app(PaymentService::class)->assignFine($fine, $this->manager->id);

    // The customer now pays the fine.
    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '5000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    $rental = ChartOfAccount::where('code', '1110')->sole();

    $rentalCredits = Transaction::where('credit_account_id', $rental->id)->sum('amount');
    $rentalDebits = Transaction::where('debit_account_id', $rental->id)->sum('amount');

    // 1110 nets to zero: the rental was invoiced once and settled once. The fine
    // payment had no rental receivable to clear, so it went to 2500, not onto 1110.
    expect((float) $rentalDebits - (float) $rentalCredits)->toBe(0.0);

    $last = Transaction::where('source_type', 'payment')->latest('id')->firstOrFail();
    expect($last->creditAccount->code)->toBe('2500');
});

/**
 * `paid` counts only rows PaymentPoster wrote. A correcting reversal of the invoice
 * also credits the receivable — counting it would show the customer as having paid
 * money they never handed over.
 */
it('does not count a reversal of the invoice as payment', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);
    $invoice = $booking->ledgerTransactions()->where('is_reversal', false)->sole();

    app(AccountingService::class)->reverse($invoice, 'customer dispute', $this->manager);

    // The reversal unwinds both sides: nothing invoiced, nothing paid, nothing owed.
    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0]);
});

it('does not count a reversed payment twice as paid', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    app(PaymentService::class)->recordBookingPayment(
        $booking,
        ['amount' => '12000.00', 'method' => PaymentMethod::Cash->value],
        $this->manager->id,
    );

    $paymentRow = Transaction::where('source_type', 'payment')->sole();
    app(AccountingService::class)->reverse($paymentRow, 'refund', $this->manager);

    // The refund reversal restores the receivable; `paid` keeps what the customer
    // actually handed over, and `outstanding` absorbs the correction.
    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 20000.0, 'paid' => 12000.0, 'outstanding' => 20000.0]);
});

it('hides the settlement section from a user without reports.view_financials', function () {
    Auth::login($this->receptionist);

    $booking = checkedOutBooking($this->manager);

    $this->get(BookingResource::getUrl('view', ['record' => $booking->getRouteKey()]))
        ->assertOk()
        ->assertDontSee(__('Outstanding'));
});

it('shows the settlement section to an accountant', function () {
    Auth::login($this->accountant);

    $booking = checkedOutBooking($this->manager);

    $this->get(BookingResource::getUrl('view', ['record' => $booking->getRouteKey()]))
        ->assertOk()
        ->assertSee(__('Outstanding'));
});

// -----------------------------------------------------------------------
// Extend — reprices and posts, never a silent date edit
// -----------------------------------------------------------------------

it('prices the extra days and posts them to the ledger', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);
    $before = Transaction::count();

    app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->addDays(2),
        $this->manager,
    );

    $booking->refresh();

    expect($booking->days_count)->toBe(6)
        ->and($booking->total_amount)->toBe('30000.00')
        ->and($booking->subtotal)->toBe('30000.00')
        ->and(Transaction::count())->toBe($before + 1);

    $row = Transaction::latest('id')->firstOrFail();

    expect($row->amount)->toEqual('10000.00')
        ->and($row->booking_id)->toBe($booking->id);
});

it('raises what the booking owes by exactly the extension', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->addDays(2),
        $this->manager,
    );

    expect(app(ReportService::class)->bookingSettlement($booking->id)['outstanding'])
        ->toBe(30000.0);
});

it('refuses to extend a booking that is not under way', function () {
    Auth::login($this->manager);

    $booking = bookingForTest(['status' => BookingStatus::Confirmed]);

    expect(fn () => app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->addDay(),
        $this->manager,
    ))->toThrow(RuntimeException::class);
});

it('refuses to extend backwards', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    expect(fn () => app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->subDay(),
        $this->manager,
    ))->toThrow(RuntimeException::class);
});

/**
 * The reason belongs on the ledger row, not in `notes` — `notes` is free text staff
 * edit, so an audit trail kept there can be typed over.
 */
it('records the extension detail on the ledger row rather than the notes', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->addDays(2),
        $this->manager,
        'Customer staying on',
    );

    $row = Transaction::latest('id')->firstOrFail();

    expect($row->meta['extension'])->toBeTrue()
        ->and($row->meta['additional_days'])->toBe(2)
        ->and($row->meta['reason'])->toBe('Customer staying on')
        ->and($booking->fresh()->notes)->toBeNull();
});

/**
 * The `EXCLUDE` constraint would reject this UPDATE on its own (SQLSTATE 23P01). What is
 * on trial here is that `extend()` refuses it *first*, with a message a receptionist can
 * act on, rather than letting a driver exception surface.
 */
it('refuses to extend over another booking of the same car', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    // Same car, starting just after this rental is due back — extending across it
    // would promise one car to two customers.
    bookingForTest([
        'car_id' => $booking->car_id,
        'status' => BookingStatus::Confirmed,
        'pickup_at' => $booking->expected_return_at->copy()->addHour(),
        'expected_return_at' => $booking->expected_return_at->copy()->addDays(4),
    ]);

    expect(fn () => app(BookingService::class)->extend(
        $booking,
        $booking->expected_return_at->copy()->addDays(3),
        $this->manager,
    ))->toThrow(RuntimeException::class);
});

// -----------------------------------------------------------------------
// Index — the working queue
// -----------------------------------------------------------------------

it('filters to overdue rentals', function () {
    Auth::login($this->manager);

    $late = bookingForTest([
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(5),
        'expected_return_at' => now()->subDay(),
    ]);
    $onTime = bookingForTest([
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDay(),
        'expected_return_at' => now()->addDays(3),
    ]);

    Livewire::test(ListBookings::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$onTime]);
});

it('filters to returns due today', function () {
    Auth::login($this->manager);

    $today = bookingForTest([
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(3),
        'expected_return_at' => now()->setTime(16, 0),
    ]);
    $tomorrow = bookingForTest([
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(3),
        'expected_return_at' => now()->addDay(),
    ]);

    Livewire::test(ListBookings::class)
        ->filterTable('returns_due_today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$tomorrow]);
});

it('filters to pickups today', function () {
    Auth::login($this->manager);

    $today = bookingForTest([
        'status' => BookingStatus::Confirmed,
        'pickup_at' => now()->setTime(9, 0),
        'expected_return_at' => now()->addDays(3),
    ]);
    $later = bookingForTest([
        'status' => BookingStatus::Confirmed,
        'pickup_at' => now()->addDays(2),
        'expected_return_at' => now()->addDays(6),
    ]);

    Livewire::test(ListBookings::class)
        ->filterTable('pickups_today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$later]);
});

/**
 * The Postgres session runs in UTC, so `whereDate` judged a 00:30 Algiers pickup as
 * belonging to the previous day. The range filter must use app-timezone bounds.
 */
it('filters by pickup range across the Algiers midnight boundary', function () {
    Auth::login($this->manager);

    $earlyMorning = bookingForTest([
        'status' => BookingStatus::Confirmed,
        'pickup_at' => now()->startOfDay()->addMinutes(30),
        'expected_return_at' => now()->addDays(3),
    ]);
    $tomorrow = bookingForTest([
        'status' => BookingStatus::Confirmed,
        'pickup_at' => now()->addDay()->startOfDay()->addHours(8),
        'expected_return_at' => now()->addDays(5),
    ]);

    Livewire::test(ListBookings::class)
        ->filterTable('pickup_range', [
            'pickup_from' => now()->toDateString(),
            'pickup_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$earlyMorning])
        ->assertCanNotSeeTableRecords([$tomorrow]);
});

it('shows the customer full name, not just the first', function () {
    Auth::login($this->manager);

    $this->customer->update(['first_name' => 'Ahmed', 'last_name' => 'Benali']);
    bookingForTest();

    Livewire::test(ListBookings::class)->assertSee('Ahmed Benali');
});

// -----------------------------------------------------------------------
// Branch scoping — pinned server-side, not by the filter control
// -----------------------------------------------------------------------

it('pins a user without branches.view_all to their own branch', function () {
    Auth::login($this->receptionist);

    $mine = bookingForTest();

    $other = Branch::factory()->create(['code' => 'ORAN']);
    $theirs = bookingForTest(['branch_id' => $other->id]);

    expect($this->receptionist->can('branches.view_all'))->toBeFalse();

    Livewire::test(ListBookings::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

/**
 * The filter narrows `branch_id` — the same column `getEloquentQuery()` pins. Filtering
 * a different notion of branch than the one enforced is how a manager ends up trusting
 * a list that does not mean what it says.
 */
it('offers the branch filter only with branches.view_all', function () {
    Auth::login($this->receptionist);
    $filters = Livewire::test(ListBookings::class)->instance()->getTable()->getFilters();
    expect(array_key_exists('branch_id', $filters))->toBeFalse();

    Auth::login($this->admin);
    $filters = Livewire::test(ListBookings::class)->instance()->getTable()->getFilters();
    expect(array_key_exists('branch_id', $filters))->toBeTrue();
});

// -----------------------------------------------------------------------
// Relation managers
// -----------------------------------------------------------------------

it('gates the money relation managers on reports.view_financials', function () {
    $booking = bookingForTest();

    $gated = [
        BookingResource\RelationManagers\DepositsRelationManager::class,
        BookingResource\RelationManagers\PaymentsRelationManager::class,
    ];

    Auth::login($this->receptionist);
    foreach ($gated as $manager) {
        expect($manager::canViewForRecord($booking, BookingResource\Pages\ViewBooking::class))->toBeFalse();
    }

    Auth::login($this->accountant);
    foreach ($gated as $manager) {
        expect($manager::canViewForRecord($booking, BookingResource\Pages\ViewBooking::class))->toBeTrue();
    }
});

it('renders the view page with its relation managers', function () {
    Auth::login($this->manager);

    $booking = checkedOutBooking($this->manager);

    $this->get(BookingResource::getUrl('view', ['record' => $booking->getRouteKey()]))
        ->assertOk()
        ->assertSee($booking->reference);
});
