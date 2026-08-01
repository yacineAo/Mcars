<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\ContractStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\ContractService;
use App\Services\Booking\PricingService;
use App\Services\Booking\SignatureService;
use App\Services\ReportService;
use Carbon\CarbonPeriod;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createCar(): Car
{
    return Car::factory()->create(['status' => CarStatus::Available, 'daily_rate' => '5000.00']);
}

function createCustomer(): Customer
{
    return Customer::factory()->create();
}

function createUser(): User
{
    return User::factory()->create();
}

function seedAccounts(): void
{
    (new ChartOfAccountSeeder)->run();
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->car = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->create();
    $this->service = app(BookingService::class);
    $this->availability = app(BookingAvailabilityService::class);
    $this->pricing = app(PricingService::class);
});

// ---------------------------------------------------------------------------
// 1. Booking creation & drafting
// ---------------------------------------------------------------------------

it('creates a draft booking', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);

    expect($booking->status)->toBe(BookingStatus::Draft)
        ->and($booking->car_id)->toBe($this->car->id)
        ->and($booking->customer_id)->toBe($this->customer->id)
        ->and((int) $booking->days_count)->toBe(4);
});

it('confirms a draft booking', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);

    $confirmed = $this->service->confirm($booking, $this->user);

    expect($confirmed->status)->toBe(BookingStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// 2. Double-booking prevention
// ---------------------------------------------------------------------------

it('prevents double-booking the same car for overlapping dates at DB level', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking1 = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking1, $this->user);

    $booking2 = $this->service->createDraft($this->car, $this->customer, $period, $this->user);

    expect(fn () => $this->service->confirm($booking2, $this->user))
        ->toThrow(RuntimeException::class);
});

it('allows non-overlapping bookings for the same car', function () {
    $p1 = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $b1 = $this->service->createDraft($this->car, $this->customer, $p1, $this->user);
    $this->service->confirm($b1, $this->user);

    $p2 = CarbonPeriod::create('2026-08-10', '2026-08-15');
    $b2 = $this->service->createDraft($this->car, $this->customer, $p2, $this->user);

    $confirmed = $this->service->confirm($b2, $this->user);
    expect($confirmed->status)->toBe(BookingStatus::Confirmed);
});

it('allows draft bookings to overlap (not yet confirmed)', function () {
    $p1 = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $b1 = $this->service->createDraft($this->car, $this->customer, $p1, $this->user);

    $p2 = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $b2 = $this->service->createDraft($this->car, $this->customer, $p2, $this->user);

    expect($b1->status)->toBe(BookingStatus::Draft);
    expect($b2->status)->toBe(BookingStatus::Draft);
});

// ---------------------------------------------------------------------------
// 3. Availability service
// ---------------------------------------------------------------------------

it('reports car as unavailable when booked', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);

    expect($this->availability->isAvailable($this->car, $period))->toBeFalse();
});

it('reports car as available on non-overlapping dates', function () {
    $p1 = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $b1 = $this->service->createDraft($this->car, $this->customer, $p1, $this->user);
    $this->service->confirm($b1, $this->user);

    $p2 = CarbonPeriod::create('2026-08-10', '2026-08-15');
    expect($this->availability->isAvailable($this->car, $p2))->toBeTrue();
});

it('reports conflicts for blocked cars', function () {
    CarBlock::create([
        'car_id' => $this->car->id,
        'reason' => 'maintenance',
        'starts_at' => '2026-08-01 00:00:00',
        'ends_at' => '2026-08-10 23:59:59',
        'created_by_id' => $this->user->id,
    ]);

    $period = CarbonPeriod::create('2026-08-05', '2026-08-08');
    expect($this->availability->isAvailable($this->car, $period))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Booking lifecycle — checkout / check-in
// ---------------------------------------------------------------------------

it('checks out a booking and posts rental revenue to the ledger', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);

    $this->service->checkOut($booking, [
        'odometer_out' => 50000,
        'fuel_level_out' => 'full',
    ], $this->user);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Active)
        ->and($booking->odometer_out)->toBe(50000);

    // Rental revenue posted to ledger
    $txn = Transaction::where('booking_id', $booking->id)->first();
    expect($txn)->not->toBeNull()
        ->and($txn->type->value)->toBe('rental_revenue');
});

it('completes a check-in', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);
    $this->service->checkOut($booking, ['odometer_out' => 50000, 'fuel_level_out' => 'full'], $this->user);

    $this->service->checkIn($booking, [
        'odometer_in' => 50400,
        'fuel_level_in' => 'half',
    ], $this->user);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->odometer_in)->toBe(50400);

    // Car returns to available
    expect($this->car->fresh()->status)->toBe(CarStatus::Available);
});

it('cancels a draft booking', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);

    $cancelled = $this->service->cancel($booking, 'Customer changed mind', $this->user);

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Customer changed mind');
});

/**
 * E09: a handed-over booking has revenue rows standing against it. The ledger is
 * append-only, so cancelling reverses each one — which also unwinds the receivable.
 */
it('reverses every booking row when a handed-over booking is cancelled', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);
    $this->service->checkOut($booking, [
        'actual_pickup_at' => '2026-08-01 10:00:00',
        'odometer_out' => 50000,
        'fuel_level_out' => 'full',
    ], $this->user);

    $invoice = $booking->ledgerTransactions()->where('is_reversal', false)->sole();

    $this->service->cancel($booking, 'Customer no-show', $this->user);

    $reversal = Transaction::where('is_reversal', true)->sole();

    expect($reversal->reverses_transaction_id)->toBe($invoice->id)
        // Mirrored legs: Dr 4010 / Cr 1110 on top of the original E02.
        ->and($reversal->debit_account_id)->toBe($invoice->credit_account_id)
        ->and($reversal->credit_account_id)->toBe($invoice->debit_account_id)
        ->and($reversal->booking_id)->toBe($booking->id)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->cancellation_reason)->toBe('Customer no-show');

    // The receivable is unwound: nothing invoiced, nothing paid, nothing owed.
    expect(app(ReportService::class)->bookingSettlement($booking->id))
        ->toMatchArray(['invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0]);
});

it('skips rows an accountant already reversed when cancelling', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);
    $this->service->checkOut($booking, [
        'actual_pickup_at' => '2026-08-01 10:00:00',
        'odometer_out' => 50000,
        'fuel_level_out' => 'full',
    ], $this->user);

    $invoice = $booking->ledgerTransactions()->where('is_reversal', false)->sole();
    app(AccountingService::class)->reverse($invoice, 'corrected amount', $this->user);

    // The E02 row is already unwound; cancelling must not try to reverse it again.
    $this->service->cancel($booking, 'Customer no-show', $this->user);

    expect(Transaction::where('is_reversal', true)->count())->toBe(1)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('refuses to cancel a completed rental', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);
    $this->service->checkOut($booking, [
        'actual_pickup_at' => '2026-08-01 10:00:00',
        'odometer_out' => 50000,
        'fuel_level_out' => 'full',
    ], $this->user);
    $this->service->checkIn($booking, [
        'odometer_in' => 50400,
        'fuel_level_in' => 'half',
    ], $this->user);

    expect(fn () => $this->service->cancel($booking->fresh(), 'Too late', $this->user))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// 5. Pricing
// ---------------------------------------------------------------------------

it('calculates a quote', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $quote = $this->pricing->quote($this->car, $period);

    expect($quote->dailyRate)->toBe('5000.00')
        ->and($quote->daysCount)->toBe(4)
        ->and($quote->subtotal)->toBe('20000.00');
});

// ---------------------------------------------------------------------------
// 6. Contract generation
// ---------------------------------------------------------------------------

it('generates a contract from a booking', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);

    $contractService = app(ContractService::class);
    $contract = $contractService->generate($booking);

    expect($contract)->toBeInstanceOf(Contract::class)
        ->and($contract->booking_id)->toBe($booking->id)
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->content_snapshot)->toHaveKey('customer')
        ->and($contract->content_snapshot)->toHaveKey('pricing');
});

it('renders a contract PDF and stores it on the private disk', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);

    $contractService = app(ContractService::class);
    $contract = $contractService->generate($booking);
    $path = $contractService->renderPdf($contract);

    expect($path)->toContain('contracts/')
        ->and($contract->fresh()->pdf_path)->toBe($path)
        ->and($contract->fresh()->document_hash)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 7. Signature OTP flow
// ---------------------------------------------------------------------------

it('verifies an OTP signature', function () {
    $period = CarbonPeriod::create('2026-08-01', '2026-08-05');
    $booking = $this->service->createDraft($this->car, $this->customer, $period, $this->user);
    $this->service->confirm($booking, $this->user);

    $contractService = app(ContractService::class);
    $contract = $contractService->generate($booking);

    $signatureService = app(SignatureService::class);
    $signatureService->requestOtp($contract, 'customer', '0555000000');

    $signature = $contract->signatures()->first();
    expect($signature)->not->toBeNull();
    expect($signature->otp_sent_to)->toBe('0555000000');
});

// ---------------------------------------------------------------------------
// 8. Concurrency: two overlapping bookings in parallel
// ---------------------------------------------------------------------------

it('prevents confirming overlapping bookings via the EXCLUDE constraint', function () {
    $b1 = Booking::create([
        'uuid' => Str::uuid(),
        'reference' => 'BK-TEST-001',
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->user->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => '2026-09-01 10:00:00',
        'expected_return_at' => '2026-09-05 10:00:00',
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'total_amount' => '20000.00',
    ]);

    $b2 = Booking::create([
        'uuid' => Str::uuid(),
        'reference' => 'BK-TEST-002',
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->user->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => '2026-09-02 10:00:00',
        'expected_return_at' => '2026-09-06 10:00:00',
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'total_amount' => '20000.00',
    ]);

    // First booking confirms successfully
    $b1->status = BookingStatus::Confirmed;
    $b1->save();

    // Second overlapping booking should be blocked by EXCLUDE constraint
    expect(fn () => DB::transaction(fn () => $b2->update(['status' => BookingStatus::Confirmed])))
        ->toThrow(QueryException::class);

    expect(Booking::where('status', BookingStatus::Confirmed)->count())->toBe(1);
});
