<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ActivityLogResource;
use App\Filament\Admin\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarDocument;
use App\Models\Customer;
use App\Models\User;
use App\Support\ActivityChanges;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->otherBranch = Branch::factory()->create(['code' => 'ORAN']);

    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);
});

it('exposes no create, edit, delete or bulk action', function () {
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => Customer::class,
        'subject_id' => 1,
    ]);

    expect(ActivityLogResource::canCreate())->toBeFalse()
        ->and(ActivityLogResource::canEdit($activity))->toBeFalse()
        ->and(ActivityLogResource::canDelete($activity))->toBeFalse();

    $this->actingAs($this->manager);

    Livewire::test(ListActivityLogs::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('refuses the whole resource without audit.view', function () {
    $this->actingAs($this->receptionist);

    $this->get(ActivityLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('stamps the subject branch on the row when a subject is written', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->otherBranch->id]);

    $row = Activity::query()
        ->where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->branch_id)->toBe($this->otherBranch->id);
});

it('falls back to the causer branch when the event has no subject', function () {
    $this->actingAs($this->manager);

    activity()->causedBy($this->manager)->log('made_default');

    $row = Activity::query()->latest('id')->first();

    expect($row->description)->toBe('made_default')
        ->and($row->branch_id)->toBe($this->branch->id);
});

it('pins the index to the pivot branches, not just the home branch', function () {
    $pinned = User::factory()->create(['branch_id' => $this->branch->id]);
    $pinned->givePermissionTo('audit.view');
    $pinned->branchUsers()->attach($this->otherBranch, ['is_primary' => false]);

    Activity::create(['log_name' => 'default', 'description' => 'a', 'branch_id' => $this->branch->id]);
    Activity::create(['log_name' => 'default', 'description' => 'b', 'branch_id' => $this->otherBranch->id]);
    Activity::create(['log_name' => 'default', 'description' => 'c', 'branch_id' => null]);

    $this->actingAs($pinned);

    $rows = ActivityLogResource::getEloquentQuery()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->branch_id)->toBe($this->otherBranch->id);
});

it('denies a branch-less user without branches.view_all entirely', function () {
    // BelongsToBranch auto-fills branch_id on create, so a true "no branch"
    // account only exists after the fact — exactly the shape this resource
    // must refuse with whereRaw('1 = 0').
    $orphan = User::factory()->create();
    $orphan->forceFill(['branch_id' => null])->save();
    $orphan->givePermissionTo('audit.view');

    Activity::create(['log_name' => 'default', 'description' => 'a', 'branch_id' => $this->branch->id]);
    Activity::create(['log_name' => 'default', 'description' => 'b']);

    $this->actingAs($orphan);

    expect(ActivityLogResource::getEloquentQuery()->get())->toBeEmpty();
});

it('redacts secrets at render time, even for rows written before the scrub', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id]);

    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'attribute_changes' => [
            'attributes' => [
                'name' => 'Alice',
                'password' => '$2y$12$REDACTMETOKEN1234567890',
                'remember_token' => 'secret-token',
            ],
            'old' => [],
        ],
    ]);

    $rows = ActivityChanges::rows($activity);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['field'])->toBe('name')
        ->and($rows[0]['new'])->toBe('Alice');

    $html = view('activity-log.attribute-changes', [
        'getRecord' => fn (): Activity => $activity,
    ])->render();

    expect($html)->toContain('Alice')
        ->not->toContain('REDACTMETOKEN');
});

it('filters by causer, subject type and subject id', function () {
    $car = Car::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => '5000.00']);
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $booking = Booking::create([
        'uuid' => (string) Str::uuid(),
        'reference' => 'BK-FILTER',
        'branch_id' => $this->branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->addDays(1),
        'expected_return_at' => now()->addDays(3),
        'daily_rate' => '5000.00',
        'days_count' => 2,
        'subtotal' => '10000.00',
        'total_amount' => '10000.00',
        'created_by_id' => $this->admin->id,
    ]);

    $byManager = Activity::create([
        'log_name' => 'default',
        'description' => 'by manager',
        'causer_type' => User::class,
        'causer_id' => $this->manager->id,
        'subject_type' => Booking::class,
        'subject_id' => $booking->id,
    ]);

    $byAdmin = Activity::create([
        'log_name' => 'default',
        'description' => 'by admin',
        'causer_type' => User::class,
        'causer_id' => $this->admin->id,
        'subject_type' => Booking::class,
        'subject_id' => $booking->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ListActivityLogs::class)
        ->filterTable('causer_id', $this->manager->id)
        ->assertCanSeeTableRecords([$byManager])
        ->assertCanNotSeeTableRecords([$byAdmin])
        ->resetTableFilters()
        ->filterTable('subject_type', Booking::class)
        ->assertCanSeeTableRecords([$byManager, $byAdmin])
        ->resetTableFilters()
        ->filterTable('subject_id', ['subject_id' => $booking->id])
        ->assertCanSeeTableRecords([$byManager, $byAdmin]);
});

it('links the subject to its view page on the index and the view page', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    expect(ActivityLogResource::subjectUrl($activity))
        ->toContain('customers/'.$customer->getKey());

    $this->actingAs($this->manager);

    $this->get(ActivityLogResource::getUrl('view', ['record' => $activity], panel: 'admin'))
        ->assertSuccessful();
});

it('leaves subjects without a view page unlinked', function () {
    $document = CarDocument::factory()->create();

    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => CarDocument::class,
        'subject_id' => $document->id,
    ]);

    expect(ActivityLogResource::subjectUrl($activity))->toBeNull();
});
