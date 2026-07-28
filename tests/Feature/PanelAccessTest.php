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

it('refuses a user with no role', function () {
    // The panel admits any staff role, so the meaningful boundary is now
    // "has a role at all" rather than "which panel".
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('/admin')
        ->assertForbidden();
});

it('no longer exposes the retired owner and client portals', function () {
    $manager = User::factory()->create();
    $manager->assignRole(UserRole::Manager->value);

    // The system is staff-only: customers and car owners are records the office
    // manages, never accounts that log in. Both portals were removed, so these
    // routes must not exist for anyone — including a privileged user.
    $this->actingAs($manager)->get('/owner')->assertNotFound();
    $this->actingAs($manager)->get('/client')->assertNotFound();
});

it('offers only staff roles', function () {
    expect(UserRole::values())
        ->not->toContain('car_owner')
        ->and(UserRole::values())->not->toContain('client')
        ->and(UserRole::cases())->toHaveCount(6);
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
