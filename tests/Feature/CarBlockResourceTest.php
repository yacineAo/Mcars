<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\CarBlockResource;
use App\Filament\Admin\Resources\CarBlockResource\Pages\CreateCarBlock;
use App\Filament\Admin\Resources\CarBlockResource\Pages\EditCarBlock;
use App\Filament\Admin\Resources\CarBlockResource\Pages\ListCarBlocks;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\CarBlockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
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

function blockForCarBlockTest(Car $car, array $overrides = []): CarBlock
{
    return CarBlock::create([
        'car_id' => $car->id,
        'reason' => BlockReason::Maintenance,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(3),
        'created_by_id' => User::firstOrFail()->id,
        ...$overrides,
    ]);
}

function bookingForCarBlockTest(Car $car, array $overrides = []): Booking
{
    return Booking::create([
        'branch_id' => Branch::where('code', 'MAIN')->firstOrFail()->id,
        'car_id' => $car->id,
        'customer_id' => Customer::firstOrFail()->id,
        'created_by_id' => User::firstOrFail()->id,
        'status' => BookingStatus::Confirmed,
        'pickup_at' => now()->addDays(5),
        'expected_return_at' => now()->addDays(7),
        'daily_rate' => '5000.00',
        'days_count' => 2,
        'subtotal' => '10000.00',
        'extras_total' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => '10000.00',
        'security_deposit_amount' => '30000.00',
        ...$overrides,
    ]);
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('lets bookings.view and fleet.manage_maintenance roles read, bookings.operate write', function () {
    // The visibility matrix scopes the maintenance officer's Bookings row to
    // blocks — reading the list is part of the workshop, writing is not.
    $this->actingAs($this->maintenance)
        ->get(CarBlockResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $this->actingAs($this->accountant)
        ->get(CarBlockResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $stranger = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->actingAs($stranger)
        ->get(CarBlockResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();

    $this->actingAs($this->accountant);
    expect(CarBlockResource::canCreate())->toBeFalse();

    $this->actingAs($this->maintenance);
    expect(CarBlockResource::canCreate())->toBeFalse();

    $this->actingAs($this->receptionist);
    expect(CarBlockResource::canCreate())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Creation goes through the service
// ---------------------------------------------------------------------------

it('creates through the service, stamping the author and the branch', function () {
    Livewire::actingAs($this->receptionist)
        ->test(CreateCarBlock::class)
        ->fillForm([
            'car_id' => $this->car->id,
            'reason' => 'maintenance',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $block = CarBlock::query()->where('car_id', $this->car->id)->first();

    expect($block)->not->toBeNull()
        ->and($block->created_by_id)->toBe($this->receptionist->id)
        ->and($block->branch_id)->toBe($this->branch->id);
});

it('refuses a window overlapping an existing block', function () {
    blockForCarBlockTest($this->car, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

    Livewire::actingAs($this->receptionist)
        ->test(CreateCarBlock::class)
        ->fillForm([
            'car_id' => $this->car->id,
            'reason' => 'other',
            'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['car_id']);

    expect(fn () => app(CarBlockService::class)->create([
        'car_id' => $this->car->id,
        'reason' => 'other',
        'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
    ], $this->receptionist))->toThrow(RuntimeException::class);

    expect(CarBlock::count())->toBe(1);
});

it('refuses a window overlapping a confirmed booking', function () {
    bookingForCarBlockTest($this->car);

    Livewire::actingAs($this->receptionist)
        ->test(CreateCarBlock::class)
        ->fillForm([
            'car_id' => $this->car->id,
            'reason' => 'maintenance',
            'starts_at' => now()->addDays(6)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(8)->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['car_id']);

    expect(CarBlock::count())->toBe(0);
});

it('backs the no-overlap rule with the database exclusion constraint', function () {
    blockForCarBlockTest($this->car, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

    expect(fn () => blockForCarBlockTest($this->car, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)]))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// The unblock action
// ---------------------------------------------------------------------------

it('ends an in-force block now, recording the early release', function () {
    $block = blockForCarBlockTest($this->car, [
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
    ]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->callTableAction('unblock', $block)
        ->callMountedTableAction();

    $ended = $block->fresh();

    expect($ended->ends_at)->not->toBeNull()
        ->and($ended->ends_at->lte(now()))->toBeTrue()
        ->and($ended->ends_at->gt(now()->subMinute()))->toBeTrue();
});

it('cancels a future block instead of inverting its window', function () {
    $block = blockForCarBlockTest($this->car, [
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(4),
    ]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->callTableAction('unblock', $block)
        ->callMountedTableAction();

    expect(CarBlock::find($block->id))->toBeNull();
});

it('hides unblock once the block has ended', function () {
    $block = blockForCarBlockTest($this->car, [
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDay(),
    ]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->assertTableActionHidden('unblock', $block);
});

it('keeps the row delete but never offers a bulk delete — cars come back via unblock, not a sweep', function () {
    $block = blockForCarBlockTest($this->car);

    $component = Livewire::actingAs($this->manager)
        ->test(ListCarBlocks::class);

    // The row delete stays (prefer unblock); the bulk sweep is gone entirely.
    $component->assertTableActionExists('delete');
    expect($component->instance()->getTable()->getBulkActions())->toBe([]);
});

// ---------------------------------------------------------------------------
// Edit
// ---------------------------------------------------------------------------

it('freezes the car on edit and re-checks the window', function () {
    $block = blockForCarBlockTest($this->car);

    Livewire::actingAs($this->receptionist)
        ->test(EditCarBlock::class, ['record' => $block->getRouteKey()])
        ->assertFormFieldDisabled('car_id')
        ->assertFormFieldEnabled('reason');

    $otherCar = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $otherBlock = blockForCarBlockTest($otherCar);

    // The service refuses a re-point at another car even if the form allowed it.
    expect(fn () => app(CarBlockService::class)->update($otherBlock, [
        'car_id' => $this->car->id,
        'starts_at' => $otherBlock->starts_at->format('Y-m-d H:i:s'),
        'ends_at' => $otherBlock->ends_at->format('Y-m-d H:i:s'),
    ]))->toThrow(RuntimeException::class);
});

it('refuses an extension that now overlaps a booking', function () {
    $block = blockForCarBlockTest($this->car);
    bookingForCarBlockTest($this->car);

    Livewire::actingAs($this->receptionist)
        ->test(EditCarBlock::class, ['record' => $block->getRouteKey()])
        ->fillForm(['ends_at' => now()->addDays(6)->format('Y-m-d H:i:s')])
        ->call('save')
        ->assertHasFormErrors(['car_id']);

    expect($block->fresh()->ends_at->toDateString())->toBe(now()->addDays(3)->toDateString());
});

it('allows shortening the window', function () {
    $block = blockForCarBlockTest($this->car);

    Livewire::actingAs($this->receptionist)
        ->test(EditCarBlock::class, ['record' => $block->getRouteKey()])
        ->fillForm(['ends_at' => now()->addDays(2)->format('Y-m-d H:i:s')])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($block->fresh()->ends_at->toDateString())->toBe(now()->addDays(2)->toDateString());
});

// ---------------------------------------------------------------------------
// The index list
// ---------------------------------------------------------------------------

it('filters by state, by car and by reason', function () {
    $active = blockForCarBlockTest($this->car, ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $upcoming = blockForCarBlockTest($this->car, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)]);
    $ended = blockForCarBlockTest($this->car, [
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDay(),
        'reason' => BlockReason::OwnerUse,
    ]);
    $otherCar = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $otherCarBlock = blockForCarBlockTest($otherCar, ['reason' => BlockReason::OwnerUse]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->filterTable('state', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$upcoming, $ended, $otherCarBlock]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->filterTable('car_id', $this->car->id)
        ->assertCanSeeTableRecords([$active, $upcoming, $ended])
        ->assertCanNotSeeTableRecords([$otherCarBlock]);

    Livewire::actingAs($this->receptionist)
        ->test(ListCarBlocks::class)
        ->filterTable('reason', 'owner_use')
        ->assertCanSeeTableRecords([$ended, $otherCarBlock])
        ->assertCanNotSeeTableRecords([$active, $upcoming]);
});
