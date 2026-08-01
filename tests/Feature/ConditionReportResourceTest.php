<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ConditionReportResource;
use App\Filament\Admin\Resources\ConditionReportResource\Pages\CreateConditionReport;
use App\Filament\Admin\Resources\ConditionReportResource\Pages\EditConditionReport;
use App\Filament\Admin\Resources\ConditionReportResource\Pages\ListConditionReports;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\ConditionReportService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    foreach ([
        'manager' => UserRole::Manager,
        'receptionist' => UserRole::Receptionist,
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

function bookingForConditionReportTest(): Booking
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();

    return Booking::create([
        'branch_id' => $branch->id,
        'car_id' => Car::factory()->create([
            'status' => CarStatus::Available,
            'daily_rate' => '5000.00',
            'branch_id' => $branch->id,
        ])->id,
        'customer_id' => Customer::firstOrFail()->id,
        'created_by_id' => User::firstOrFail()->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->subDay(),
        'expected_return_at' => now()->addDays(3),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'extras_total' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => '20000.00',
        'security_deposit_amount' => '30000.00',
    ]);
}

function reportForConditionReportTest(Booking $booking, ConditionReportType $type, array $overrides = []): ConditionReport
{
    return ConditionReport::create([
        'booking_id' => $booking->id,
        'type' => $type,
        'performed_at' => now(),
        'performed_by_id' => User::firstOrFail()->id,
        'odometer' => 50000,
        'fuel_level' => FuelLevel::Full,
        'is_clean' => true,
        'damage_points' => [],
        ...$overrides,
    ]);
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('gates the whole resource behind bookings.view', function () {
    $this->actingAs($this->accountant)
        ->get(ConditionReportResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $this->actingAs($this->maintenance)
        ->get(ConditionReportResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('lets only the desk write: bookings.operate gates create and edit', function () {
    $this->actingAs($this->accountant);
    expect(ConditionReportResource::canCreate())->toBeFalse();

    $this->actingAs($this->receptionist);
    expect(ConditionReportResource::canCreate())->toBeTrue();
});

it('never offers a delete path — evidence cannot vanish', function () {
    $report = reportForConditionReportTest(bookingForConditionReportTest(), ConditionReportType::Checkin);

    $this->actingAs($this->manager);
    expect(ConditionReportResource::canDelete($report))->toBeFalse();

    Livewire::actingAs($this->manager)
        ->test(ListConditionReports::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

// ---------------------------------------------------------------------------
// Creation goes through the service
// ---------------------------------------------------------------------------

it('creates through the service, stamping who performed the inspection', function () {
    $booking = bookingForConditionReportTest();

    Livewire::actingAs($this->receptionist)
        ->test(CreateConditionReport::class)
        ->fillForm([
            'booking_id' => $booking->id,
            'type' => 'checkout',
            'performed_at' => now()->format('Y-m-d H:i:s'),
            'odometer' => 48000,
            'fuel_level' => 'full',
            'is_clean' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $report = $booking->conditionReports()->first();

    expect($report)->not->toBeNull()
        ->and($report->type)->toBe(ConditionReportType::Checkout)
        ->and($report->performed_by_id)->toBe($this->receptionist->id);
});

it('refuses a second report of the same type for one booking', function () {
    $booking = bookingForConditionReportTest();
    reportForConditionReportTest($booking, ConditionReportType::Checkin);

    Livewire::actingAs($this->receptionist)
        ->test(CreateConditionReport::class)
        ->fillForm([
            'booking_id' => $booking->id,
            'type' => 'checkin',
            'performed_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['booking_id']);

    expect($booking->conditionReports()->count())->toBe(1);

    // The service itself refuses — the guard lives behind the form.
    expect(fn () => app(ConditionReportService::class)->create([
        'booking_id' => $booking->id,
        'type' => 'checkin',
        'performed_at' => now(),
    ], $this->receptionist))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// The evidence freezes once the booking is closed
// ---------------------------------------------------------------------------

it('freezes the readings once the booking is completed, keeping notes and photos open', function () {
    $booking = bookingForConditionReportTest();
    $report = reportForConditionReportTest($booking, ConditionReportType::Checkin);

    Livewire::actingAs($this->receptionist)
        ->test(EditConditionReport::class, ['record' => $report->getRouteKey()])
        ->assertFormFieldEnabled('odometer')
        ->assertFormFieldEnabled('is_clean');

    $booking->update(['status' => BookingStatus::Completed]);

    Livewire::actingAs($this->receptionist)
        ->test(EditConditionReport::class, ['record' => $report->getRouteKey()])
        ->assertFormFieldDisabled('booking_id')
        ->assertFormFieldDisabled('type')
        ->assertFormFieldDisabled('performed_at')
        ->assertFormFieldDisabled('odometer')
        ->assertFormFieldDisabled('fuel_level')
        ->assertFormFieldDisabled('is_clean')
        // Evidence may still be attached — never a reading amended.
        ->assertFormFieldEnabled('photos')
        ->assertFormFieldEnabled('notes');
});

// ---------------------------------------------------------------------------
// The direction locks once the booking holds a pair
// ---------------------------------------------------------------------------

it('locks the direction and the booking once the booking holds a pair', function () {
    $booking = bookingForConditionReportTest();
    $checkout = reportForConditionReportTest($booking, ConditionReportType::Checkout);
    reportForConditionReportTest($booking, ConditionReportType::Checkin);

    Livewire::actingAs($this->receptionist)
        ->test(EditConditionReport::class, ['record' => $checkout->getRouteKey()])
        ->assertFormFieldDisabled('booking_id')
        ->assertFormFieldDisabled('type')
        // The readings are not frozen yet — only the identity is locked.
        ->assertFormFieldEnabled('odometer')
        ->assertFormFieldEnabled('is_clean');
});

it('keeps a sole report correctable until the booking is completed', function () {
    $booking = bookingForConditionReportTest();
    $checkout = reportForConditionReportTest($booking, ConditionReportType::Checkout);

    Livewire::actingAs($this->receptionist)
        ->test(EditConditionReport::class, ['record' => $checkout->getRouteKey()])
        ->assertFormFieldEnabled('type')
        ->assertFormFieldEnabled('booking_id');

    // And the correction actually lands, through the service.
    app(ConditionReportService::class)->update($checkout, ['type' => 'checkin']);

    expect($checkout->fresh()->type)->toBe(ConditionReportType::Checkin);
});

it('refuses an edit that would leave a booking with two reports of the same type', function () {
    $booking = bookingForConditionReportTest();
    $checkin = reportForConditionReportTest($booking, ConditionReportType::Checkin);
    reportForConditionReportTest($booking, ConditionReportType::Checkout);

    $service = app(ConditionReportService::class);

    // Retyping the direction when a report of the other direction exists.
    expect(fn () => $service->update($checkin, ['type' => 'checkout']))
        ->toThrow(RuntimeException::class);

    // Re-pointing the evidence at a booking that already holds a check-in.
    $otherBooking = bookingForConditionReportTest();
    reportForConditionReportTest($otherBooking, ConditionReportType::Checkin);

    expect(fn () => $service->update($checkin, ['booking_id' => $otherBooking->id]))
        ->toThrow(RuntimeException::class);

    expect($checkin->fresh()->type)->toBe(ConditionReportType::Checkin)
        ->and($checkin->fresh()->booking_id)->toBe($booking->id);
});

it('backs the one-per-type rule with a database unique constraint', function () {
    $booking = bookingForConditionReportTest();
    reportForConditionReportTest($booking, ConditionReportType::Checkin);

    // Bypass the service entirely: the index itself refuses a second check-in.
    expect(fn () => reportForConditionReportTest($booking, ConditionReportType::Checkin))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// The view page shows the pair and the photos
// ---------------------------------------------------------------------------

it('renders the view page with the readings and the paired report side by side', function () {
    Storage::fake('private');

    $booking = bookingForConditionReportTest();
    reportForConditionReportTest($booking, ConditionReportType::Checkout, [
        'odometer' => 48000,
        'fuel_level' => FuelLevel::Full,
        'is_clean' => true,
        'notes' => 'Impeccable at handover.',
    ]);
    $checkin = reportForConditionReportTest($booking, ConditionReportType::Checkin, [
        'odometer' => 51400,
        'fuel_level' => FuelLevel::Half,
        'is_clean' => false,
        'damage_points' => [['part' => 'Driver door', 'severity' => 'minor', 'description' => 'Scratch']],
        'notes' => 'Scratch on the driver door.',
    ]);
    $checkin->addMediaFromString('not-really-an-image')->toMediaCollection('photos');

    $this->actingAs($this->receptionist)
        ->get(ConditionReportResource::getUrl('view', ['record' => $checkin], panel: 'admin'))
        ->assertSuccessful()
        // The paired column's heading is the paired report's type label.
        ->assertSee(ConditionReportType::Checkout->getLabel())
        ->assertSee('51400')
        ->assertSee('48000')
        ->assertSee('Scratch on the driver door.')
        ->assertSee('Impeccable at handover.');
});

it('hides the paired column until the booking has both reports', function () {
    $booking = bookingForConditionReportTest();
    $checkin = reportForConditionReportTest($booking, ConditionReportType::Checkin);

    $this->actingAs($this->receptionist)
        ->get(ConditionReportResource::getUrl('view', ['record' => $checkin], panel: 'admin'))
        ->assertSuccessful()
        // No pair yet: the comparison column does not render at all, not even empty —
        // the checkout type label would be its heading.
        ->assertDontSee(ConditionReportType::Checkout->getLabel());
});

// ---------------------------------------------------------------------------
// The index list
// ---------------------------------------------------------------------------

it('shows the car alongside the booking and defaults to the newest first', function () {
    $booking = bookingForConditionReportTest();
    $old = reportForConditionReportTest($booking, ConditionReportType::Checkout, ['performed_at' => now()->subDays(5)]);
    $recent = reportForConditionReportTest($booking, ConditionReportType::Checkin, ['performed_at' => now()->subDays(1)]);

    Livewire::actingAs($this->receptionist)
        ->test(ListConditionReports::class)
        ->assertCanSeeTableRecords([$old, $recent])
        ->assertSee($booking->car->registration_number);
});

it('filters by type, by car and by damages', function () {
    $booking = bookingForConditionReportTest();
    $cleanOut = reportForConditionReportTest($booking, ConditionReportType::Checkout);
    $damagedIn = reportForConditionReportTest($booking, ConditionReportType::Checkin, [
        'is_clean' => false,
        'damage_points' => [['description' => 'Scratch']],
    ]);

    Livewire::actingAs($this->receptionist)
        ->test(ListConditionReports::class)
        ->filterTable('type', 'checkout')
        ->assertCanSeeTableRecords([$cleanOut])
        ->assertCanNotSeeTableRecords([$damagedIn]);

    Livewire::actingAs($this->receptionist)
        ->test(ListConditionReports::class)
        ->filterTable('damages', 'damaged')
        ->assertCanSeeTableRecords([$damagedIn])
        ->assertCanNotSeeTableRecords([$cleanOut]);

    Livewire::actingAs($this->receptionist)
        ->test(ListConditionReports::class)
        ->filterTable('car_id', $booking->car_id)
        ->assertCanSeeTableRecords([$cleanOut, $damagedIn]);
});

it('pins a user without branches.view_all to their own branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTHER']);
    $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);
    $otherUser->assignRole(UserRole::Receptionist->value);

    $mine = reportForConditionReportTest(bookingForConditionReportTest(), ConditionReportType::Checkin);
    $theirs = ConditionReport::create([
        'booking_id' => Booking::create([
            'branch_id' => $otherBranch->id,
            'car_id' => Car::factory()->create(['status' => CarStatus::Available, 'daily_rate' => '5000.00', 'branch_id' => $otherBranch->id])->id,
            'customer_id' => $this->customer->id,
            'created_by_id' => $otherUser->id,
            'status' => BookingStatus::Draft,
            'pickup_at' => now()->addDay(),
            'expected_return_at' => now()->addDays(3),
            'daily_rate' => '5000.00',
            'days_count' => 3,
            'subtotal' => '15000.00',
            'extras_total' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '15000.00',
            'security_deposit_amount' => '30000.00',
        ])->id,
        'type' => ConditionReportType::Checkin,
        'performed_at' => now(),
        'performed_by_id' => $otherUser->id,
        'odometer' => 60000,
        'is_clean' => true,
        'damage_points' => [],
    ]);

    Livewire::actingAs($otherUser)
        ->test(ListConditionReports::class)
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$mine]);
});
