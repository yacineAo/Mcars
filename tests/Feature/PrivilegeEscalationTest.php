<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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

it('gates user management on users.manage, not branches.view_all', function () {
    // The old gate was branches.view_all — cross-branch *visibility* — so anyone granted
    // it picked up staff-account management as a side effect.
    expect($this->admin->can('users.manage'))->toBeTrue()
        ->and($this->manager->can('users.manage'))->toBeTrue()
        ->and($this->receptionist->can('users.manage'))->toBeFalse();

    $this->actingAs($this->receptionist);
    expect(UserResource::canAccess())->toBeFalse();
    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertForbidden();

    // docs/02-filament-panels.md §Role → visibility matrix puts Settings & Access at
    // "full" for a manager, so this must stay allowed.
    $this->actingAs($this->manager);
    expect(UserResource::canAccess())->toBeTrue();
    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertSuccessful();
});

it('never offers a manager a role they do not hold', function () {
    $this->actingAs($this->manager);

    $assignable = UserResource::assignableRoles();

    expect($assignable)->not->toHaveKey(UserRole::SuperAdmin->value)
        ->and($assignable)->toHaveKey(UserRole::Receptionist->value);

    // A super_admin may hand out anything, including their own role.
    $this->actingAs($this->admin);
    expect(UserResource::assignableRoles())->toHaveKey(UserRole::SuperAdmin->value);
});

it('refuses a manager assigning super_admin through the action', function () {
    $this->actingAs($this->manager);

    // The CheckboxList itself validates the selection against the actor's assignable
    // options (an In rule listing only the roles the actor holds), so the post is
    // rejected at the form boundary — hiding an option is not security, rejecting it is.
    Livewire::test(ListUsers::class)
        ->callTableAction('assign_roles', $this->receptionist, ['roles' => [UserRole::SuperAdmin->value]])
        ->assertHasTableActionErrors(['roles.0']);

    expect($this->receptionist->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeFalse()
        ->and($this->receptionist->fresh()->hasRole(UserRole::Receptionist->value))->toBeTrue();
});

it('keeps the assign-roles action off the acting user\'s own record', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('assign_roles', record: $this->manager)
        ->assertTableActionExists('assign_roles', record: $this->receptionist);

    // Visibility is UI, not security — the service refuses it outright too.
    expect(fn () => app(UserService::class)->assignRoles(
        $this->manager,
        [UserRole::SuperAdmin->value],
        $this->manager,
    ))->toThrow(DomainException::class);
});

it('refuses a manager changing the roles of a super_admin account', function () {
    $this->actingAs($this->manager);

    expect(fn () => app(UserService::class)->assignRoles(
        $this->admin,
        [UserRole::Receptionist->value],
        $this->manager,
    ))->toThrow(DomainException::class);

    expect($this->admin->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
});

it('lets a super_admin still assign super_admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('assign_roles', $this->receptionist, [
            'roles' => [UserRole::SuperAdmin->value, UserRole::Receptionist->value],
        ])
        ->assertNotified();

    expect($this->receptionist->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
});

it('makes the ledger reversal path reachable', function () {
    // ViewTransaction gates its reverse action on reverse_transaction. The permission was
    // never seeded, and because Shield's super_admin has 'define_via_gate' => false with no
    // Gate::before, an unseeded permission is denied to everyone — leaving an append-only
    // ledger with no sanctioned way to correct a mis-posting.
    $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $accountant->assignRole(UserRole::Accountant->value);

    expect($this->admin->can('reverse_transaction'))->toBeTrue()
        ->and($accountant->can('reverse_transaction'))->toBeTrue()
        // Reversing is an accounting act; a manager runs the business, not the books.
        ->and($this->manager->can('reverse_transaction'))->toBeFalse()
        ->and($this->receptionist->can('reverse_transaction'))->toBeFalse();
});

it('keeps secrets out of the activity log', function () {
    Auth::login($this->admin);

    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $user->update(['password' => 'a-new-password', 'name' => 'Renamed']);

    $rows = DB::table('activity_log')
        ->where('subject_type', User::class)
        ->pluck('attribute_changes');

    // logAll() serialises every attribute, and Eloquent's $hidden does not apply to Spatie.
    // The trait now excludes getHidden(), so no bcrypt hash may reach the table — which
    // ActivityLogResource renders on screen.
    expect($rows->implode(' '))->not->toMatch('/\$2[aby]\$\d{2}\$/');

    // Asserted on decoded keys, not substrings: `must_change_password` is a legitimate
    // non-secret column and contains the word "password".
    $secrets = (new User)->getHidden();
    $loggedKeys = [];

    foreach ($rows as $payload) {
        $decoded = json_decode((string) $payload, true) ?? [];

        foreach (['attributes', 'old'] as $group) {
            $loggedKeys = [...$loggedKeys, ...array_keys($decoded[$group] ?? [])];
        }
    }

    expect(array_intersect($secrets, $loggedKeys))->toBe([])
        // The audit trail itself must survive: a non-secret change is still recorded.
        ->and($rows->implode(' '))->toContain('Renamed');
});
