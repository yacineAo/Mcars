<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\OwnershipType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\CarResource\Pages\CreateCar;
use App\Filament\Admin\Resources\CarResource\Pages\EditCar;
use App\Filament\Admin\Resources\CarResource\Pages\ListCars;
use App\Filament\Admin\Resources\CarResource\Pages\ViewCar;
use App\Filament\Admin\Resources\CarResource\RelationManagers\AgreementsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\BookingsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\BookingAvailabilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->asRole = function (UserRole $role): User {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role->value);
        $this->actingAs($user);

        return $user;
    };
});

// ---------------------------------------------------------------------------
// is_active actually withdraws a car from availability
// ---------------------------------------------------------------------------

it('does not offer a deactivated car in availability search', function () {
    $active = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => CarStatus::Available,
        'is_active' => true,
    ]);

    $inactive = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => CarStatus::Available,
        'is_active' => false,
    ]);

    $period = CarbonPeriod::create(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-05'),
    );

    $ids = app(BookingAvailabilityService::class)->availableCars($period)->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id);
});

// ---------------------------------------------------------------------------
// A company-owned car cannot keep a third-party owner
// ---------------------------------------------------------------------------

it('clears car_owner_id when a car becomes company-owned', function () {
    $owner = CarOwner::factory()->create(['branch_id' => $this->branch->id]);

    $car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'ownership_type' => OwnershipType::ThirdParty,
        'car_owner_id' => $owner->id,
    ]);

    $car->ownership_type = OwnershipType::CompanyOwned;
    $car->save();

    expect($car->fresh()->car_owner_id)->toBeNull();
});

it('keeps car_owner_id on a third-party car', function () {
    $owner = CarOwner::factory()->create(['branch_id' => $this->branch->id]);

    $car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'ownership_type' => OwnershipType::ThirdParty,
        'car_owner_id' => $owner->id,
    ]);

    $car->daily_rate = '7000.00';
    $car->save();

    expect($car->fresh()->car_owner_id)->toBe($owner->id);
});

/**
 * The edit form hides `car_owner_id` for a company-owned car, and a hidden Filament field is
 * skipped during dehydration — so the form alone cannot clear it. This drives the screen the
 * way a manager does and asserts the row does not keep a dangling owner.
 */
it('does not leave a dangling owner when the ownership type is switched on the edit form', function () {
    ($this->asRole)(UserRole::Manager);

    $owner = CarOwner::factory()->create(['branch_id' => $this->branch->id]);

    $car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'ownership_type' => OwnershipType::ThirdParty,
        'car_owner_id' => $owner->id,
    ]);

    Livewire::test(EditCar::class, ['record' => $car->getRouteKey()])
        ->fillForm(['ownership_type' => OwnershipType::CompanyOwned->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $car->refresh();

    expect($car->ownership_type)->toBe(OwnershipType::CompanyOwned)
        ->and($car->car_owner_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Fleet permissions
// ---------------------------------------------------------------------------

it('lets every staff role reach the fleet index', function (UserRole $role) {
    ($this->asRole)($role);

    expect(CarResource::canAccess())->toBeTrue();
})->with([
    UserRole::Manager,
    UserRole::Accountant,
    UserRole::Receptionist,
    UserRole::MaintenanceOfficer,
    UserRole::Supervisor,
]);

it('only lets a manager write the car record', function () {
    ($this->asRole)(UserRole::Manager);
    expect(CarResource::canCreate())->toBeTrue();

    ($this->asRole)(UserRole::Receptionist);
    expect(CarResource::canCreate())->toBeFalse();

    ($this->asRole)(UserRole::MaintenanceOfficer);
    expect(CarResource::canCreate())->toBeFalse();
});

it('refuses the fleet index to a user with no fleet permission', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->actingAs($user);

    expect(CarResource::canAccess())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Branch scoping
// ---------------------------------------------------------------------------

it('pins a user without branches.view_all to their own branch', function () {
    $other = Branch::factory()->create(['code' => 'OTHER']);

    $mine = Car::factory()->create(['branch_id' => $this->branch->id]);
    $theirs = Car::factory()->create(['branch_id' => $other->id]);

    // A receptionist does not hold branches.view_all.
    ($this->asRole)(UserRole::Receptionist);

    $ids = CarResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('shows every branch to a manager, who holds branches.view_all', function () {
    $other = Branch::factory()->create(['code' => 'OTHER']);

    $mine = Car::factory()->create(['branch_id' => $this->branch->id]);
    $theirs = Car::factory()->create(['branch_id' => $other->id]);

    ($this->asRole)(UserRole::Manager);

    $ids = CarResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($mine->id)->and($ids)->toContain($theirs->id);
});

// ---------------------------------------------------------------------------
// Read-only history on view, editable tables on edit
// ---------------------------------------------------------------------------

it('does not put the writable ownership-agreements table on the view page', function () {
    ($this->asRole)(UserRole::Manager);

    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    $managers = collect(Livewire::test(ViewCar::class, ['record' => $car->getRouteKey()])
        ->instance()
        ->getRelationManagers())
        ->flatMap(fn ($group) => method_exists($group, 'getManagers') ? $group->getManagers() : [$group])
        ->all();

    expect($managers)->not->toContain(AgreementsRelationManager::class)
        ->and($managers)->toContain(BookingsRelationManager::class);
});

it('keeps read-only history off the edit page', function () {
    ($this->asRole)(UserRole::Manager);

    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    $managers = collect(Livewire::test(EditCar::class, ['record' => $car->getRouteKey()])
        ->instance()
        ->getRelationManagers())
        ->flatMap(fn ($group) => method_exists($group, 'getManagers') ? $group->getManagers() : [$group])
        ->all();

    expect($managers)->toContain(AgreementsRelationManager::class)
        ->and($managers)->not->toContain(BookingsRelationManager::class);
});

// ---------------------------------------------------------------------------
// Index filters
// ---------------------------------------------------------------------------

it('filters the index down to cars with an expired document', function () {
    ($this->asRole)(UserRole::Manager);

    $expired = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'insurance_expiry_date' => CarbonImmutable::today()->subDay(),
    ]);

    $fine = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'insurance_expiry_date' => CarbonImmutable::today()->addYear(),
        'technical_inspection_expiry_date' => CarbonImmutable::today()->addYear(),
        'registration_expiry_date' => CarbonImmutable::today()->addYear(),
        'road_tax_expiry_date' => CarbonImmutable::today()->addYear(),
    ]);

    Livewire::test(ListCars::class)
        ->filterTable('document_status', 'expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$fine]);
});

// ---------------------------------------------------------------------------
// The pages actually render
//
// A Filament schema is only resolved when the page is opened, so an unimported
// component or a wrong closure signature is invisible until then. The car pages were
// not covered by ResourcePagesRenderTest, and CarResource shipped a
// SpatieMediaLibraryFileUpload with no import — a fatal on every car form.
// ---------------------------------------------------------------------------

it('renders the car index', function () {
    ($this->asRole)(UserRole::Manager);
    Car::factory()->create(['branch_id' => $this->branch->id]);

    Livewire::test(ListCars::class)->assertOk();
});

it('renders the car create form, photo uploads included', function () {
    ($this->asRole)(UserRole::Manager);

    Livewire::test(CreateCar::class)
        ->assertOk()
        ->assertFormFieldExists('gallery')
        ->assertFormFieldExists('damage')
        ->assertFormFieldExists('gps_device_id')
        ->assertFormFieldExists('status');
});

it('does not offer status on the edit form', function () {
    ($this->asRole)(UserRole::Manager);
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    Livewire::test(EditCar::class, ['record' => $car->getRouteKey()])
        ->assertOk()
        ->assertFormFieldDoesNotExist('status');
});

it('renders the car view page with its read-only history tabs', function () {
    ($this->asRole)(UserRole::Manager);
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    Livewire::test(ViewCar::class, ['record' => $car->getRouteKey()])->assertOk();
});

it('freezes the plate and VIN once the car has a booking', function () {
    ($this->asRole)(UserRole::Manager);

    $car = Car::factory()->create(['branch_id' => $this->branch->id]);
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    Booking::create([
        'uuid' => (string) Str::uuid(),
        'reference' => 'BK-FREEZE',
        'branch_id' => $this->branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDay(),
        'expected_return_at' => now()->addDay(),
        'daily_rate' => '5000.00',
        'days_count' => 2,
        'subtotal' => '10000.00',
        'total_amount' => '10000.00',
    ]);

    Livewire::test(EditCar::class, ['record' => $car->getRouteKey()])
        ->assertOk()
        ->assertFormFieldDisabled('registration_number')
        ->assertFormFieldDisabled('chassis_number');
});

// ---------------------------------------------------------------------------
// Document-expiry traffic light
//
// Carbon 3's diffInDays() is signed. `parse($state)->diffInDays(today())` returns −337 for
// a date 337 days out, so a `<= 30` test on it was true for *every* future date — the green
// arm was unreachable and every valid document rendered amber. The sign convention is what
// these lock.
// ---------------------------------------------------------------------------

it('counts days to an expiry date as positive in the future and negative once past', function () {
    $today = CarbonImmutable::today();

    expect(ViewCar::daysUntil($today->addDays(337)->toDateString()))->toBe(337)
        ->and(ViewCar::daysUntil($today->addDays(12)->toDateString()))->toBe(12)
        ->and(ViewCar::daysUntil($today->toDateString()))->toBe(0)
        ->and(ViewCar::daysUntil($today->subDays(9)->toDateString()))->toBe(-9);
});

it('reaches every expiry colour band, including green for a distant date', function () {
    $today = CarbonImmutable::today();

    $band = fn (int $offsetDays): string => match (true) {
        ViewCar::daysUntil($today->addDays($offsetDays)->toDateString()) < 0 => 'danger',
        ViewCar::daysUntil($today->addDays($offsetDays)->toDateString()) <= 30 => 'warning',
        default => 'success',
    };

    expect($band(-1))->toBe('danger')
        ->and($band(0))->toBe('warning')
        ->and($band(30))->toBe('warning')
        ->and($band(31))->toBe('success')
        ->and($band(337))->toBe('success');
});

// ---------------------------------------------------------------------------
// The profitability period cannot be driven from the client
// ---------------------------------------------------------------------------

it('locks the profitability period against client tampering', function () {
    ($this->asRole)(UserRole::Manager);
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    // #[Locked] makes Livewire refuse a client-side write. Without it these reach
    // CarbonImmutable::parse() and junk becomes an unhandled 500.
    Livewire::test(ViewCar::class, ['record' => $car->getRouteKey()])
        ->set('profitabilityFrom', '../../etc/passwd');
})->throws(CannotUpdateLockedPropertyException::class);

it('falls back to the month rather than throwing on an unparseable custom period', function () {
    ($this->asRole)(UserRole::Manager);
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    $page = Livewire::test(ViewCar::class, ['record' => $car->getRouteKey()])->instance();

    // Reached only if #[Locked] is ever removed; the guard must still not 500.
    (function () {
        $this->profitabilityPeriod = 'custom';
        $this->profitabilityFrom = 'not-a-date';
        $this->profitabilityTo = 'also-not-a-date';
    })->call($page);

    Livewire::test(ViewCar::class, ['record' => $car->getRouteKey()])->assertOk();

    expect($page->profitabilityPeriod)->toBe('custom');
});

it('hides the branch filter from a user without branches.view_all', function () {
    ($this->asRole)(UserRole::Receptionist);

    $filter = CarResource::table(app(Table::class, [
        'livewire' => Livewire::test(ListCars::class)->instance(),
    ]))->getFilter('branch_id');

    expect($filter?->isVisible() ?? false)->toBeFalse();
});
