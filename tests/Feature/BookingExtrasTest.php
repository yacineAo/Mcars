<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BookingResource\Pages\ViewBooking;
use App\Filament\Admin\Resources\BookingResource\RelationManagers\ExtrasRelationManager;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Extra;
use App\Models\User;
use App\Services\Booking\BookingService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * `bookings.extras_total` (and the `total_amount` it moves) is derived from the
 * extras lines. These tests pin the recompute to the line's create / edit / delete:
 * without it the booking keeps whatever the wizard typed — and the ledger posted
 * (E04) — while the lines show something else.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->assignRole(UserRole::Manager->value);
    $this->car = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $this->extra = Extra::create([
        'name' => 'GPS unit',
        'code' => 'GPS',
        'pricing_unit' => 'per_day',
        'unit_price' => '500.00',
        'ledger_account_id' => ChartOfAccount::where('code', '4020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $this->secondExtra = Extra::create([
        'name' => 'Baby seat',
        'code' => 'BABY',
        'pricing_unit' => 'per_day',
        'unit_price' => '300.00',
        'ledger_account_id' => ChartOfAccount::where('code', '4020')->valueOrFail('id'),
        'is_active' => true,
    ]);

    $this->booking = Booking::create([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->user->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->addDay(),
        'expected_return_at' => now()->addDays(5),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'extras_total' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => '20000.00',
        'security_deposit_amount' => '0.00',
    ]);
});

function bookingExtraLine(Booking $booking, Extra $extra, string $total): BookingExtra
{
    return BookingExtra::create([
        'booking_id' => $booking->id,
        'extra_id' => $extra->id,
        'quantity' => 1,
        'unit_price' => $total,
        'total' => $total,
    ]);
}

it('recomputes extras_total and total_amount when a line is added', function () {
    bookingExtraLine($this->booking, $this->extra, '500.00');

    expect($this->booking->fresh()->extras_total)->toBe('500.00')
        ->and($this->booking->fresh()->total_amount)->toBe('20500.00');
});

it('recomputes both columns when a line is edited', function () {
    $line = bookingExtraLine($this->booking, $this->extra, '500.00');

    $line->update(['total' => '800.00']);

    expect($this->booking->fresh()->extras_total)->toBe('800.00')
        ->and($this->booking->fresh()->total_amount)->toBe('20800.00');
});

it('recomputes both columns when a line is deleted', function () {
    $line = bookingExtraLine($this->booking, $this->extra, '500.00');
    bookingExtraLine($this->booking, $this->secondExtra, '300.00');

    $line->delete();

    expect($this->booking->fresh()->extras_total)->toBe('300.00')
        ->and($this->booking->fresh()->total_amount)->toBe('20300.00');
});

it('adds up quantity on the line rather than requiring duplicate rows', function () {
    // `(booking_id, extra_id)` is unique, so two GPS units are quantity 2, not two rows.
    BookingExtra::create([
        'booking_id' => $this->booking->id,
        'extra_id' => $this->extra->id,
        'quantity' => 2,
        'unit_price' => '500.00',
        'total' => '1000.00',
    ]);

    expect($this->booking->fresh()->extras_total)->toBe('1000.00')
        ->and($this->booking->fresh()->total_amount)->toBe('21000.00');
});

it('corrects a typed extras figure the moment a line is touched', function () {
    // The wizard used to let staff type extras_total; the lines are the truth.
    $this->booking->update(['extras_total' => '2000.00', 'total_amount' => '22000.00']);

    bookingExtraLine($this->booking, $this->extra, '500.00');

    expect($this->booking->fresh()->extras_total)->toBe('500.00')
        ->and($this->booking->fresh()->total_amount)->toBe('20500.00');
});

// -----------------------------------------------------------------------
// Frozen once the rental is under way — enforced here, not in the UI
// -----------------------------------------------------------------------

/**
 * The relation manager hides the buttons, but the rule cannot live only there: a
 * started booking's `total_amount` is what E02/E04 posted, and the ledger is
 * append-only. Every one of these reaches the table without passing that form.
 */
it('refuses to add a line once the rental has started', function (BookingStatus $status) {
    $this->booking->update(['status' => $status]);

    expect(fn () => bookingExtraLine($this->booking, $this->extra, '500.00'))
        ->toThrow(RuntimeException::class);

    // And nothing was written: the guard runs before the insert, not after it.
    expect($this->booking->fresh()->extras()->count())->toBe(0)
        ->and($this->booking->fresh()->total_amount)->toBe('20000.00');
})->with([BookingStatus::Active, BookingStatus::Overdue, BookingStatus::Completed]);

it('refuses to edit a line once the rental has started', function () {
    $line = bookingExtraLine($this->booking, $this->extra, '500.00');
    $this->booking->update(['status' => BookingStatus::Active]);

    expect(fn () => $line->update(['total' => '9000.00']))
        ->toThrow(RuntimeException::class);

    expect($line->fresh()->total)->toBe('500.00')
        ->and($this->booking->fresh()->total_amount)->toBe('20500.00');
});

it('refuses to delete a line once the rental has started', function () {
    $line = bookingExtraLine($this->booking, $this->extra, '500.00');
    $this->booking->update(['status' => BookingStatus::Active]);

    expect(fn () => $line->delete())->toThrow(RuntimeException::class);

    expect($this->booking->fresh()->extras()->count())->toBe(1)
        ->and($this->booking->fresh()->total_amount)->toBe('20500.00');
});

it('refuses to recompute the totals of a started booking', function () {
    $this->booking->update(['status' => BookingStatus::Active]);

    expect(fn () => app(BookingService::class)->syncExtrasTotals($this->booking))
        ->toThrow(RuntimeException::class);
});

// -----------------------------------------------------------------------
// The line total is computed, never typed
// -----------------------------------------------------------------------

it('prices a line as quantity times unit price', function () {
    $priced = app(BookingService::class)->priceExtraLine([
        'quantity' => 3,
        'unit_price' => '500.00',
        // A figure the operator typed that agrees with nothing — it is discarded.
        'total' => '50.00',
    ]);

    expect($priced['total'])->toBe('1500.00');
});

/**
 * The service method is only half the fix — the relation manager has to actually route
 * through it. A `total` field that is disabled but never recomputed would submit an
 * empty string and post a zero line.
 */
it('prices the line through the relation manager, ignoring a submitted total', function () {
    $this->actingAs($this->user);

    Livewire::test(ExtrasRelationManager::class, [
        'ownerRecord' => $this->booking,
        'pageClass' => ViewBooking::class,
    ])
        ->callTableAction('create', data: [
            'extra_id' => $this->extra->id,
            'quantity' => 3,
            'unit_price' => '500.00',
            'total' => '1.00',
        ])
        ->assertHasNoTableActionErrors();

    $line = $this->booking->extras()->sole();

    expect($line->total)->toBe('1500.00')
        ->and($this->booking->fresh()->extras_total)->toBe('1500.00')
        ->and($this->booking->fresh()->total_amount)->toBe('21500.00');
});

/**
 * The guard and the recompute both need the parent, and both must see it fresh — but
 * they run microseconds apart with only this line's own write in between, so loading it
 * twice was pure waste. Pinned because the obvious "tidy-up" is to drop the hand-off and
 * call `parentBooking()` in both places again.
 *
 * The remaining load is Spatie's `resolveModelForLogging()`, which is the price of the
 * activity-log row — see the `saveQuietly()` note on `syncExtrasTotals()`.
 */
it('loads the parent booking once for the guard and the recompute', function () {
    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_contains($query->sql, 'from "bookings" where "bookings"."id"')) {
            $selects[] = $query->sql;
        }
    });

    bookingExtraLine($this->booking, $this->extra, '500.00');

    expect($selects)->toHaveCount(1);
});

it('records who changed a booking total through its extras', function () {
    $this->actingAs($this->user);

    bookingExtraLine($this->booking, $this->extra, '500.00');

    // saveQuietly() suppressed HasAuditColumns, so a money column moved with nobody
    // attached to it.
    expect($this->booking->fresh()->updated_by_id)->toBe($this->user->id);
});
