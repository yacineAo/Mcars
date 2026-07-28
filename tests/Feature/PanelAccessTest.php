<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

it('prevents client from accessing admin panel', function () {
    $client = User::factory()->create();
    $client->assignRole(UserRole::Client->value);

    $this->actingAs($client)
        ->get('/admin')
        ->assertForbidden();
});

it('prevents receptionist from accessing owner portal', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(UserRole::Receptionist->value);

    $this->actingAs($receptionist)
        ->get('/owner')
        ->assertForbidden();
});

it('prevents receptionist from accessing client portal', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(UserRole::Receptionist->value);

    $this->actingAs($receptionist)
        ->get('/client')
        ->assertForbidden();
});

it('allows car_owner to access owner portal', function () {
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::CarOwner->value);

    $this->actingAs($owner)
        ->get('/owner')
        ->assertRedirect();
});

it('allows client to access client portal', function () {
    $client = User::factory()->create();
    $client->assignRole(UserRole::Client->value);

    $this->actingAs($client)
        ->get('/client')
        ->assertRedirect();
});

it('allows admin roles to access admin panel', function (UserRole $role) {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    // Since Phase 7 the panel has a dashboard, so /admin renders rather than
    // bouncing to the first available resource.
    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful();
})->with([
    UserRole::SuperAdmin,
    UserRole::Manager,
    UserRole::Accountant,
    UserRole::Receptionist,
    UserRole::MaintenanceOfficer,
    UserRole::Supervisor,
]);

it('returns zero branches when user has no branch and no global permission', function () {
    Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);

    $user = User::withoutEvents(fn () => User::factory()->create(['branch_id' => null]));
    $user->assignRole(UserRole::Receptionist->value);

    expect($user->accessibleBranchIds())->toBe([]);
});

it('returns all branches when user has branches.view_all', function () {
    Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);
    Branch::factory()->create(['code' => 'ORAN', 'is_default' => false]);
    Branch::factory()->create(['code' => 'CONS', 'is_default' => false]);

    $user = User::factory()->create();
    $user->assignRole(UserRole::SuperAdmin->value);

    $ids = $user->accessibleBranchIds();
    expect($ids)->toHaveCount(Branch::count());
});

it('returns pivot branches when user has pivot rows', function () {
    Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);
    $branchA = Branch::factory()->create(['code' => 'ORAN', 'is_default' => false]);
    $branchB = Branch::factory()->create(['code' => 'CONS', 'is_default' => false]);

    $user = User::withoutEvents(fn () => User::factory()->create(['branch_id' => null]));
    $user->assignRole(UserRole::Receptionist->value);
    $user->branchUsers()->attach([$branchA->id, $branchB->id]);

    $ids = $user->accessibleBranchIds();
    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($branchA->id, $branchB->id);
});

it('returns user branch when no pivot and no global permission', function () {
    $branch = Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);

    $user = User::withoutEvents(fn () => User::factory()->create(['branch_id' => $branch->id]));
    $user->assignRole(UserRole::Receptionist->value);

    expect($user->accessibleBranchIds())->toBe([$branch->id]);
});
