<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\RoleResource;
use App\Filament\Admin\Resources\RoleResource\Pages\EditRole;
use App\Filament\Admin\Resources\RoleResource\Pages\ListRoles;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);
});

it('gates role administration on roles.manage, not on being a staff member', function () {
    // docs/resource/34-role.md gap 1: with no canAccess() and no policy, Filament
    // treats a missing policy as permission granted and all six staff roles could
    // open the screen — a receptionist could edit super_admin's permission set.
    expect($this->admin->can('roles.manage'))->toBeTrue()
        ->and($this->manager->can('roles.manage'))->toBeTrue()
        ->and($this->receptionist->can('roles.manage'))->toBeFalse();

    $this->actingAs($this->receptionist);
    expect(RoleResource::canAccess())->toBeFalse();
    $this->get(RoleResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $role = Role::findByName(UserRole::SuperAdmin->value);
    $this->get(RoleResource::getUrl('edit', ['record' => $role], panel: 'admin'))->assertForbidden();

    $this->actingAs($this->manager);
    expect(RoleResource::canAccess())->toBeTrue();
    $this->get(RoleResource::getUrl('index', panel: 'admin'))->assertSuccessful();
});

it('preserves every seeded permission when a role is saved through the resource', function (UserRole $roleEnum) {
    // Round 34's central regression: the permission tabs rendered none of the
    // enforced permissions while showing 456 inert Shield checkboxes, so a
    // no-op save synced each role to an empty permission set — money, user
    // administration and both log resources became unreachable for everybody,
    // with no UI path back. The custom tab now lists exactly what the app
    // enforces, so saving preserves it.
    $role = Role::findByName($roleEnum->value);
    $expected = $role->permissions->pluck('name')->sort()->values()->all();

    $this->actingAs($this->admin);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($expected);
})->with(fn () => UserRole::cases());

it('keeps create and delete out of the role surface — the seeder is the only writer', function () {
    // A seventh role has no UserRole case, so its holders could never log in;
    // deleting super_admin locks out the only account that could restore it.
    // A view page exists (read-only name + permission list) but adds no write
    // surface of its own.
    expect(RoleResource::getPages())->not->toHaveKey('create');

    $this->actingAs($this->admin);

    $role = Role::findByName(UserRole::SuperAdmin->value);

    Livewire::test(ListRoles::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('delete', record: $role)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertActionDoesNotExist('delete');
});

it('freezes the role name and hides the guard on edit', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditRole::class, ['record' => Role::findByName(UserRole::Manager->value)->getKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldDisabled('name')
        ->assertFormFieldIsHidden('guard_name');
});

it('keeps the enforced permission list in step between seeder and config', function () {
    // A permission added to the seeder without a config/filament-shield.php
    // entry would be stripped from every role on the next save — the exact
    // failure mode Round 34 exists to end. Ordered via canonicalize so a
    // mere reordering of either list does not fail the build.
    expect(config('filament-shield.custom_permissions'))
        ->toEqualCanonicalizing(RolePermissionSeeder::permissionNames());
});
