<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DepositStatus;
use App\Enums\FuelLevel;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/**
 * The UI must actually post.
 *
 * The Phase 4–6 posters were fully built and tested, but nothing in the admin
 * panel called them: recording a payment wrote a `payments` row and stopped, and
 * "check out" was a bare status flip that never invoiced the rental. Every balance
 * in the system was therefore wrong in the running app while the unit tests stayed
 * green, because the unit tests called the services directly.
 *
 * These tests drive the Filament actions the way a receptionist does, and assert
 * the ledger moved. They are the regression guard for that whole class of bug.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->assignRole(UserRole::Manager->value);
    $this->actingAs($this->user);

    $this->car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
    ]);

    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $this->makeBooking = fn (string $status = BookingStatus::Confirmed->value): Booking => Booking::create([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'status' => $status,
        'pickup_at' => '2026-08-01 10:00:00',
        'expected_return_at' => '2026-08-05 10:00:00',
        'daily_rate' => 5000.00,
        'days_count' => 4,
        'subtotal' => 20000.00,
        'total_amount' => 20000.00,
        'created_by_id' => $this->user->id,
    ]);
});

// ---------------------------------------------------------------------------
// Booking — revenue must be recognised at hand-over
// ---------------------------------------------------------------------------

it('posts rental revenue when the car is handed over', function () {
    $booking = ($this->makeBooking)();

    expect(Transaction::query()->count())->toBe(0);

    Livewire::test(BookingResource\Pages\ListBookings::class)
        ->callTableAction('checkout', $booking, [
            'actual_pickup_at' => '2026-08-01 10:00:00',
            'odometer_out' => 45000,
            'fuel_level_out' => FuelLevel::Full->value,
        ]);

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Active)
        ->and($booking->odometer_out)->toBe(45000)
        // The car is out with a customer, so the fleet count must agree.
        ->and($booking->car->fresh()->status)->toBe(CarStatus::Rented);

    // E02: Dr Accounts Receivable / Cr Rental Revenue for the booking total.
    $posting = Transaction::query()->where('source_type', 'booking')->firstOrFail();

    expect($posting->source_id)->toBe($booking->id)
        ->and($posting->amount)->toEqual('20000.00')
        ->and($posting->debitAccount->code)->toBe('1110')
        ->and($posting->creditAccount->code)->toBe('4010')
        ->and($posting->car_id)->toBe($this->car->id)
        ->and($posting->customer_id)->toBe($this->customer->id);
});

it('does not offer check-out before a booking is confirmed', function () {
    $booking = ($this->makeBooking)(BookingStatus::Draft->value);

    Livewire::test(BookingResource\Pages\ListBookings::class)
        ->assertTableActionHidden('checkout', $booking)
        ->assertTableActionVisible('confirm', $booking);
});

it('releases the car and closes the rental on check-in', function () {
    $booking = ($this->makeBooking)();

    Livewire::test(BookingResource\Pages\ListBookings::class)
        ->callTableAction('checkout', $booking, [
            'actual_pickup_at' => '2026-08-01 10:00:00',
            'odometer_out' => 45000,
            'fuel_level_out' => FuelLevel::Full->value,
        ])
        ->callTableAction('checkin', $booking->fresh(), [
            'actual_return_at' => '2026-08-05 10:00:00',
            'odometer_in' => 45600,
            'fuel_level_in' => FuelLevel::Full->value,
        ]);

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->odometer_in)->toBe(45600)
        ->and($booking->car->fresh()->status)->toBe(CarStatus::Available);
});

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

it('posts a customer payment to the ledger as soon as it is recorded', function () {
    $booking = ($this->makeBooking)();

    // Give the customer something to owe: revenue posts at hand-over.
    Livewire::test(BookingResource\Pages\ListBookings::class)
        ->callTableAction('checkout', $booking, [
            'actual_pickup_at' => '2026-08-01 10:00:00',
            'odometer_out' => 45000,
            'fuel_level_out' => FuelLevel::Full->value,
        ]);

    Livewire::test(PaymentResource\Pages\CreatePayment::class)
        ->fillForm([
            'reference' => 'PAY-TEST-1',
            'direction' => 'inbound',
            'customer_id' => $this->customer->id,
            'method' => PaymentMethod::Cash->value,
            'amount' => 12000,
            'paid_at' => '2026-08-02',
            'status' => 'completed',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::query()->firstOrFail();

    // E10: Dr Main Cash Box / Cr Accounts Receivable.
    $posting = Transaction::query()->where('source_type', 'payment')->sole();

    expect($payment->isPostedToLedger())->toBeTrue()
        ->and($posting->source_id)->toBe($payment->id)
        ->and($posting->amount)->toEqual('12000.00')
        ->and($posting->debitAccount->code)->toBe('1010')
        ->and($posting->creditAccount->code)->toBe('1110')
        ->and($posting->customer_id)->toBe($this->customer->id);
});

it('posts the part of a payment beyond what is owed as a credit balance (E19)', function () {
    $booking = ($this->makeBooking)();

    Livewire::test(BookingResource\Pages\ListBookings::class)
        ->callTableAction('checkout', $booking, [
            'actual_pickup_at' => '2026-08-01 10:00:00',
            'odometer_out' => 45000,
            'fuel_level_out' => FuelLevel::Full->value,
        ])
        ->callTableAction('record_payment', $booking->fresh(), data: [
            'amount' => '25000.00',
            'method' => PaymentMethod::Cash->value,
        ])
        ->assertHasNoTableActionErrors();

    $rows = Transaction::query()->where('source_type', 'payment')->orderBy('id')->get();

    expect($rows)->toHaveCount(2);

    // E10: the receivable clears up to what the booking owes.
    $clearsAr = $rows->get(0);
    expect($clearsAr->amount)->toEqual('20000.00')
        ->and($clearsAr->debitAccount->code)->toBe('1010')
        ->and($clearsAr->creditAccount->code)->toBe('1110')
        ->and($clearsAr->booking_id)->toBe($booking->id)
        ->and($clearsAr->customer_id)->toBe($this->customer->id);

    // E19: the remainder is a credit held for the customer — never fabricated AR.
    // It keeps the booking dimension: the credit was paid against this rental, and
    // `bookingSettlement()` nets it into `paid` so the booking does not read as unpaid
    // while the customer's money sits on account.
    $credit = $rows->get(1);
    expect($credit->amount)->toEqual('5000.00')
        ->and($credit->debitAccount->code)->toBe('1010')
        ->and($credit->creditAccount->code)->toBe('2500')
        ->and($credit->booking_id)->toBe($booking->id)
        ->and($credit->customer_id)->toBe($this->customer->id);
});

it('credits the whole payment to the balance account when nothing is owed (E19)', function () {
    // Confirmed but never handed over: the customer owes nothing, so there is no
    // receivable for a payment to clear.
    ($this->makeBooking)();

    Livewire::test(PaymentResource\Pages\CreatePayment::class)
        ->fillForm([
            'reference' => 'PAY-TEST-CREDIT',
            'direction' => 'inbound',
            'customer_id' => $this->customer->id,
            'method' => PaymentMethod::Cash->value,
            'amount' => 5000,
            'paid_at' => '2026-08-02',
            'status' => 'completed',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = Transaction::query()->where('source_type', 'payment')->sole();

    expect($row->amount)->toEqual('5000.00')
        ->and($row->debitAccount->code)->toBe('1010')
        ->and($row->creditAccount->code)->toBe('2500');
});

it('hides the manual post action once a payment is on the ledger', function () {
    Livewire::test(PaymentResource\Pages\CreatePayment::class)
        ->fillForm([
            'reference' => 'PAY-TEST-2',
            'direction' => 'inbound',
            'customer_id' => $this->customer->id,
            'method' => PaymentMethod::Cash->value,
            'amount' => 5000,
            'paid_at' => '2026-08-02',
            'status' => 'completed',
        ])
        ->call('create');

    $payment = Payment::query()->firstOrFail();

    // The retry path exists for failures only — it must not invite a double post.
    Livewire::test(PaymentResource\Pages\ListPayments::class)
        ->assertTableActionHidden('post_to_ledger', $payment);
});

// ---------------------------------------------------------------------------
// Deposits — the liability rule
// ---------------------------------------------------------------------------

it('holds a deposit as a liability, never as revenue', function () {
    $booking = ($this->makeBooking)();

    $deposit = Deposit::create([
        'booking_id' => $booking->id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'amount' => 30000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
    ]);

    Livewire::test(DepositResource\Pages\ListDeposits::class)
        ->callTableAction('hold', $deposit);

    // E22: Dr Cash / Cr Security Deposits Held (2100) — a liability.
    $posting = Transaction::query()->where('source_type', 'deposit')->firstOrFail();

    expect($posting->amount)->toEqual('30000.00')
        ->and($posting->debitAccount->code)->toBe('1010')
        ->and($posting->creditAccount->code)->toBe('2100');

    // The whole design turns on this: holding a deposit must not create income.
    expect($posting->creditAccount->type->value)->toBe('liability');
});

it('converts only the deducted part of a deposit into revenue', function () {
    $booking = ($this->makeBooking)();

    $deposit = Deposit::create([
        'booking_id' => $booking->id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'amount' => 30000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
    ]);

    Livewire::test(DepositResource\Pages\ListDeposits::class)
        ->callTableAction('hold', $deposit)
        ->callTableAction('deduct', $deposit->fresh(), [
            'reason' => 'damage',
            'amount' => 8000,
            'description' => 'Scratched bumper',
        ]);

    // The deduction moves 8 000 out of the liability into damage-recovery revenue.
    $deduction = Transaction::query()
        ->where('source_type', 'deposit')
        ->where('amount', '8000.00')
        ->firstOrFail();

    expect($deduction->debitAccount->code)->toBe('2100')
        ->and($deduction->creditAccount->code)->toBe('4060');

    // The remaining 22 000 is still owed back to the customer.
    expect($deposit->fresh()->status)->toBe(DepositStatus::PartiallyRefunded);
});

it('refuses a deduction larger than the deposit', function () {
    $booking = ($this->makeBooking)();

    $deposit = Deposit::create([
        'booking_id' => $booking->id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'amount' => 10000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
    ]);

    Livewire::test(DepositResource\Pages\ListDeposits::class)
        ->callTableAction('hold', $deposit);

    // Over-deducting would invent money the customer never paid.
    expect(fn () => Livewire::test(DepositResource\Pages\ListDeposits::class)
        ->callTableAction('deduct', $deposit->fresh(), [
            'reason' => 'damage',
            'amount' => 15000,
            'description' => 'Too much',
        ]))->toThrow(\RuntimeException::class);
});
