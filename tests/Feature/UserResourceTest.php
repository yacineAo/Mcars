<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\EditProfile;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Filament\Admin\Resources\UserResource\RelationManagers\BranchUsersRelationManager;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);
});

it('creates a user with a branch placement', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Hire',
            'email' => 'hire@mcars.test',
            'password' => 'password',
            'locale' => 'fr',
            'branch_id' => $this->branch->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'hire@mcars.test')->value('branch_id'))->toBe($this->branch->id);
});

it('validates a duplicate phone instead of leaking a QueryException', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Duplicate Phone',
            'email' => 'dup@mcars.test',
            'password' => 'password',
            'locale' => 'fr',
            'phone' => $this->receptionist->phone,
        ])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(User::where('email', 'dup@mcars.test')->exists())->toBeFalse();
});

it('allows two users without a phone', function () {
    $this->actingAs($this->manager);

    foreach (['a@mcars.test', 'b@mcars.test'] as $email) {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'No Phone',
                'email' => $email,
                'password' => 'password',
                'locale' => 'fr',
                'phone' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(User::count())->toBe(6);
});

it('keeps roles and secrets out of the forms and the table', function () {
    $this->actingAs($this->admin);

    // Role assignment moved out of the form into the guarded action — it must not
    // reappear here, where a manager could tick super_admin on their own record.
    Livewire::test(CreateUser::class)
        ->assertFormFieldDoesNotExist('roles');

    Livewire::test(EditUser::class, ['record' => $this->accountant->getKey()])
        ->assertFormFieldDoesNotExist('roles')
        ->assertFormFieldDoesNotExist('password')
        ->assertFormFieldDoesNotExist('remember_token')
        ->assertFormFieldDoesNotExist('two_factor_secret')
        ->assertFormFieldDoesNotExist('two_factor_recovery_codes');

    Livewire::test(ListUsers::class)
        ->assertTableColumnDoesNotExist('password')
        ->assertTableColumnDoesNotExist('remember_token')
        ->assertTableColumnDoesNotExist('two_factor_secret')
        ->assertTableColumnDoesNotExist('two_factor_recovery_codes');
});

it('hides deactivated users by default and surfaces them through the filter', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->accountant]);

    $this->accountant->update(['is_active' => false]);

    Livewire::test(ListUsers::class)
        ->assertCanNotSeeTableRecords([$this->accountant]);

    Livewire::test(ListUsers::class)
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$this->accountant]);
});

it('deactivates and reactivates through the action, and blocks panel access', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListUsers::class)
        ->callTableAction('set_active', $this->accountant)
        ->assertNotified();

    expect($this->accountant->fresh()->is_active)->toBeFalse()
        ->and($this->accountant->fresh()->canAccessPanel(app('filament')->getPanel('admin')))->toBeFalse();

    $this->actingAs($this->manager);

    // Deactivated users vanish behind the default filter — surface them to reactivate.
    Livewire::test(ListUsers::class)
        ->filterTable('is_active', false)
        ->callTableAction('set_active', $this->accountant)
        ->assertNotified();

    expect($this->accountant->fresh()->is_active)->toBeTrue()
        ->and($this->accountant->fresh()->canAccessPanel(app('filament')->getPanel('admin')))->toBeTrue();
});

it('cuts panel access for a deactivated user on the very next request', function () {
    app(UserService::class)->setActive($this->accountant, false, $this->admin);

    $this->actingAs($this->accountant);
    $this->get('/admin')->assertForbidden();
});

it('never lets anyone deactivate themselves', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('set_active', record: $this->manager);

    expect(fn () => app(UserService::class)->setActive(
        $this->manager,
        false,
        $this->manager,
    ))->toThrow(DomainException::class);

    expect($this->manager->fresh()->is_active)->toBeTrue();
});

it('resets a password and forces a change at the next request', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListUsers::class)
        ->callTableAction('reset_password', $this->accountant, [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertNotified();

    $this->accountant->refresh();

    expect($this->accountant->must_change_password)->toBeTrue()
        ->and(Hash::check('brand-new-password', $this->accountant->password))->toBeTrue();
});

it('bounces every non-profile request to the profile while the flag is set', function () {
    $this->accountant->update(['must_change_password' => true]);
    $this->actingAs($this->accountant);

    $this->get('/admin')->assertRedirect(Filament::getProfileUrl());
    $this->get('/admin/profile')->assertSuccessful();
});

it('clears the forced-change flag when the user saves a new password on the profile page', function () {
    $this->accountant->update(['must_change_password' => true]);
    $this->actingAs($this->accountant);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'password' => 'changed-by-user',
            'passwordConfirmation' => 'changed-by-user',
            'currentPassword' => 'password',
        ])
        ->call('save');

    expect($this->accountant->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('changed-by-user', $this->accountant->fresh()->password))->toBeTrue();
});

it('lets a receptionist change their own locale on the profile page', function () {
    $this->actingAs($this->receptionist);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $this->receptionist->name,
            'locale' => Locale::Arabic->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->receptionist->fresh()->locale)->toBe(Locale::Arabic);
});

it('records the last login on sign-in', function () {
    $this->receptionist->update(['last_login_at' => null, 'last_login_ip' => null]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $this->receptionist->email,
            'password' => 'password',
        ])
        ->call('authenticate');

    $this->receptionist->refresh();

    expect($this->receptionist->last_login_at)->not->toBeNull()
        ->and($this->receptionist->last_login_ip)->not->toBeNull();
});

it('audits role assignments and password resets with the actor', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListUsers::class)
        ->callTableAction('assign_roles', $this->receptionist, ['roles' => [UserRole::Supervisor->value]])
        ->assertNotified();

    $rolesLog = DB::table('activity_log')
        ->where('subject_type', User::class)
        ->where('subject_id', $this->receptionist->id)
        ->where('event', 'roles_updated')
        ->first();

    expect($rolesLog)->not->toBeNull()
        ->and((int) $rolesLog->causer_id)->toBe($this->manager->id)
        ->and(json_decode((string) $rolesLog->properties, true)['roles'])->toBe([UserRole::Supervisor->value]);

    Livewire::test(ListUsers::class)
        ->callTableAction('reset_password', $this->receptionist, [
            'password' => 'fresh-password-1',
            'password_confirmation' => 'fresh-password-1',
        ])
        ->assertNotified();

    expect(DB::table('activity_log')
        ->where('subject_type', User::class)
        ->where('subject_id', $this->receptionist->id)
        ->where('event', 'password_reset')
        ->where('causer_id', $this->manager->id)
        ->exists())->toBeTrue();
});

it('has no delete anywhere — accounts are parked, not destroyed', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionDoesNotExist('delete', record: $this->accountant)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditUser::class, ['record' => $this->accountant->getKey()])
        ->assertActionDoesNotExist('delete');
});

it('attaches, edits the pivot and detaches branch assignments on edit', function () {
    $this->actingAs($this->admin);

    $second = Branch::factory()->create(['code' => 'OULED']);

    $manager = Livewire::test(BranchUsersRelationManager::class, [
        'ownerRecord' => $this->receptionist,
        'pageClass' => EditUser::class,
    ]);

    $manager
        ->callTableAction('attach', data: [
            'recordId' => $second->id,
            'is_primary' => true,
        ])
        ->assertNotified();

    $this->receptionist->refresh();

    expect($this->receptionist->branchUsers()->pluck('branches.id')->all())->toContain($second->id)
        ->and($this->receptionist->branchUsers()->where('branches.id', $second->id)->first()->pivot->is_primary)->toBeTrue();

    $manager
        ->callTableAction('edit', $second, [
            'is_primary' => false,
        ])
        ->assertNotified();

    expect($this->receptionist->branchUsers()->where('branches.id', $second->id)->first()->pivot->is_primary)->toBeFalse();

    $manager
        ->callTableAction('detach', $second)
        ->assertNotified();

    expect($this->receptionist->branchUsers()->pluck('branches.id')->all())->not->toContain($second->id);
});

it('renders the resource pages with data', function () {
    $this->actingAs($this->admin);

    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertSuccessful();
    $this->get(UserResource::getUrl('create', panel: 'admin'))->assertSuccessful();
    $this->get(UserResource::getUrl('edit', ['record' => $this->accountant], panel: 'admin'))->assertSuccessful();
});
