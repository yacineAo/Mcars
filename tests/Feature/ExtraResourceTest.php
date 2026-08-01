<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ExtraResource;
use App\Filament\Admin\Resources\ExtraResource\Pages\CreateExtra;
use App\Filament\Admin\Resources\ExtraResource\Pages\EditExtra;
use App\Filament\Admin\Resources\ExtraResource\Pages\ListExtras;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Extra;
use App\Models\User;
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

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->supervisor->assignRole(UserRole::Supervisor->value);

    $this->maintenance = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->maintenance->assignRole(UserRole::MaintenanceOfficer->value);

    $this->revenueAccount = ChartOfAccount::where('code', '4020')->firstOrFail();
});

function extraForTest(array $overrides = []): Extra
{
    return Extra::create(array_merge([
        'name' => 'GPS unit',
        'code' => 'GPS',
        'pricing_unit' => 'per_day',
        'unit_price' => '500.00',
        'ledger_account_id' => ChartOfAccount::where('code', '4020')->valueOrFail('id'),
        'is_active' => true,
    ], $overrides));
}

function bookingExtraForTest(Extra $extra, array $overrides = []): BookingExtra
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $car = Car::factory()->create(['branch_id' => $branch->id, 'daily_rate' => '5000.00']);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $user = User::firstOrFail();

    $booking = Booking::create([
        'uuid' => (string) Str::uuid(),
        'reference' => 'BK-EXTRA-'.Str::upper(Str::random(6)),
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'created_by_id' => $user->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->addDays(2),
        'expected_return_at' => now()->addDays(6),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'extras_total' => '2000.00',
        'total_amount' => '22000.00',
    ]);

    return BookingExtra::create(array_merge([
        'booking_id' => $booking->id,
        'extra_id' => $extra->id,
        'quantity' => 1,
        'unit_price' => '500.00',
        'total' => '500.00',
    ], $overrides));
}

// -----------------------------------------------------------------------
// Access — everyone on the Bookings row reads, only the manager writes
// -----------------------------------------------------------------------

it('lets the manager read, create and edit the catalogue', function () {
    Auth::login($this->manager);

    expect(ExtraResource::canAccess())->toBeTrue()
        ->and(ExtraResource::canCreate())->toBeTrue()
        ->and(ExtraResource::canEdit(extraForTest()))->toBeTrue();

    $this->get(ExtraResource::getUrl('index'))->assertOk();
    $this->get(ExtraResource::getUrl('create'))->assertOk();
});

it('lets a receptionist scan the catalogue but not touch it', function () {
    Auth::login($this->receptionist);

    expect(ExtraResource::canAccess())->toBeTrue()
        ->and(ExtraResource::canCreate())->toBeFalse()
        ->and(ExtraResource::canEdit(extraForTest()))->toBeFalse();

    $this->get(ExtraResource::getUrl('index'))->assertOk();
    $this->get(ExtraResource::getUrl('create'))->assertForbidden();
});

it('lets an accountant read the catalogue but not touch it', function () {
    Auth::login($this->accountant);

    expect(ExtraResource::canAccess())->toBeTrue()
        ->and(ExtraResource::canCreate())->toBeFalse();

    $this->get(ExtraResource::getUrl('index'))->assertOk();
});

it('lets a supervisor read the catalogue', function () {
    Auth::login($this->supervisor);

    expect(ExtraResource::canAccess())->toBeTrue()
        ->and(ExtraResource::canCreate())->toBeFalse();

    $this->get(ExtraResource::getUrl('index'))->assertOk();
});

it('denies the maintenance officer, whose bookings read is blocks only', function () {
    Auth::login($this->maintenance);

    expect(ExtraResource::canAccess())->toBeFalse();

    $this->get(ExtraResource::getUrl('index'))->assertForbidden();
});

// -----------------------------------------------------------------------
// Delete — guarded on the extra never having been sold
// -----------------------------------------------------------------------

it('allows deleting an extra that was never sold', function () {
    Auth::login($this->manager);

    $extra = extraForTest();

    expect(ExtraResource::canDelete($extra))->toBeTrue();

    Livewire::test(ListExtras::class)
        ->removeTableFilters()
        ->assertTableActionVisible('delete', record: $extra);
});

it('refuses to delete an extra once a booking has sold it', function () {
    Auth::login($this->manager);

    $extra = extraForTest();
    bookingExtraForTest($extra);

    expect(ExtraResource::canDelete($extra))->toBeFalse();

    Livewire::test(ListExtras::class)
        ->removeTableFilters()
        ->assertTableActionHidden('delete', record: $extra);
});

// -----------------------------------------------------------------------
// Edit — code freezes once sold, price stays editable
// -----------------------------------------------------------------------

it('freezes the code once the extra has been sold', function () {
    Auth::login($this->manager);

    $sold = extraForTest();
    bookingExtraForTest($sold);

    $unsold = extraForTest(['code' => 'SEAT', 'name' => 'Child seat']);

    Livewire::test(EditExtra::class, ['record' => $sold->getRouteKey()])
        ->assertFormFieldDisabled('code');

    Livewire::test(EditExtra::class, ['record' => $unsold->getRouteKey()])
        ->assertFormFieldEnabled('code');
});

it('keeps the price editable even once sold — bookings snapshot their own price', function () {
    Auth::login($this->manager);

    $extra = extraForTest();
    $line = bookingExtraForTest($extra, ['unit_price' => '500.00', 'total' => '500.00']);

    Livewire::test(EditExtra::class, ['record' => $extra->getRouteKey()])
        ->fillForm(['unit_price' => '900.00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($line->fresh()->unit_price)->toBe('500.00')
        ->and($line->fresh()->total)->toBe('500.00')
        // The booking carries the line snapshot: 20 000 rental + the 500 line.
        ->and($line->booking->fresh()->total_amount)->toBe('20500.00');
});

// -----------------------------------------------------------------------
// Index — active extras first, pricing-unit filter
// -----------------------------------------------------------------------

it('defaults the active filter to on and sorts by name', function () {
    $inactive = extraForTest(['code' => 'B', 'name' => 'B extra', 'is_active' => false]);
    $first = extraForTest(['code' => 'A', 'name' => 'A extra', 'is_active' => true]);
    $second = extraForTest(['code' => 'C', 'name' => 'C extra', 'is_active' => true]);

    Auth::login($this->manager);

    Livewire::test(ListExtras::class)
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);

    Livewire::test(ListExtras::class)
        ->filterTable('is_active', false)
        ->assertCountTableRecords(1);

    expect($inactive->exists)->toBeTrue();
});

// -----------------------------------------------------------------------
// Create — ledger account restricted to postable revenue accounts
// -----------------------------------------------------------------------

it('only offers postable revenue accounts for the ledger mapping', function () {
    Auth::login($this->manager);

    $page = Livewire::test(CreateExtra::class);
    $schema = collect($page->instance()->getSchema('form')->getComponents())
        ->first(fn ($component) => $component->getName() === 'ledger_account_id');

    expect($schema)->not->toBeNull();

    $results = $schema->getSearchResults($this->revenueAccount->name);
    $resultKeys = array_map('strval', array_keys($results));

    expect($resultKeys)->toContain((string) $this->revenueAccount->id);

    $expenseAccount = ChartOfAccount::where('type', AccountType::Expense)->first();
    expect($expenseAccount)->not->toBeNull();

    $expenseResults = array_map('strval', array_keys($schema->getSearchResults($expenseAccount->name)));

    expect($expenseResults)->not->toContain((string) $expenseAccount->id);
});
