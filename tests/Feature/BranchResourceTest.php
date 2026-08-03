<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Wilaya;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranches;
use App\Filament\Admin\Resources\BranchResource\RelationManagers\UsersRelationManager;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->manager = User::factory()->create();
    $this->manager->assignRole('manager');

    $this->receptionist = User::factory()->create();
    $this->receptionist->assignRole('receptionist');
});

it('gates the resource behind branches.view_all', function () {
    $this->actingAs($this->receptionist);
    $this->get(BranchResource::getUrl('index', panel: 'admin'))->assertForbidden();

    $this->actingAs($this->manager);
    $this->get(BranchResource::getUrl('index', panel: 'admin'))->assertSuccessful();
    $this->get(BranchResource::getUrl('create', panel: 'admin'))->assertSuccessful();
    $this->get(BranchResource::getUrl('edit', ['record' => $this->branch], panel: 'admin'))->assertSuccessful();
});

it('creates a branch without a default toggle and stores the wilaya from the vocabulary', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateBranch::class)
        ->assertFormFieldDoesNotExist('is_default')
        ->fillForm([
            'name' => 'Oran Sud',
            'code' => 'oran2',
            'wilaya' => Wilaya::Oran->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $branch = Branch::where('name', 'Oran Sud')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->code)->toBe('ORAN2')
        ->and($branch->wilaya)->toBe(Wilaya::Oran->value);
});

it('rejects a code that differs only in case from an existing branch', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Duplicate Code',
            'code' => 'main',
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Branch::where('name', 'Duplicate Code')->exists())->toBeFalse();
});

it('rejects a code longer than the eight-character column', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Too Long Code',
            'code' => 'ABCDEFGHI',
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('rejects a wilaya outside the enum at the database level', function () {
    expect(fn () => DB::table('branches')->insert([
        'name' => 'Atlantis',
        'code' => 'ATL',
        'wilaya' => 'atlantis',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('freezes the code once documents have been numbered for the branch', function () {
    $this->actingAs($this->manager);

    DB::table('sequences')->insert([
        'key' => 'contract',
        'branch_id' => $this->branch->id,
        'year' => 2026,
        'prefix' => 'CTR-MAIN',
        'padding' => 6,
        'next_number' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(EditBranch::class, ['record' => $this->branch->getKey()])
        ->assertFormFieldDisabled('code');
});

it('moves the default flag atomically and clears the old holder', function () {
    $other = Branch::factory()->create();

    app(BranchService::class)->makeDefault($other, $this->admin);

    expect(Branch::where('is_default', true)->count())->toBe(1)
        ->and(Branch::default()?->id)->toBe($other->id)
        ->and($this->branch->fresh()->is_default)->toBeFalse();
});

it('promotes a new default after the previous one was soft-deleted', function () {
    $other = Branch::factory()->create();

    $this->branch->delete();
    app(BranchService::class)->makeDefault($other, $this->admin);

    expect(Branch::default()?->id)->toBe($other->id);
});

it('refuses to make an inactive branch the default', function () {
    $inactive = Branch::factory()->inactive()->create();

    expect(fn () => app(BranchService::class)->makeDefault($inactive, $this->admin))
        ->toThrow(DomainException::class)
        ->and(Branch::default()?->id)->toBe($this->branch->id);
});

it('promotes a branch from the list action', function () {
    $this->actingAs($this->manager);
    $other = Branch::factory()->create();

    Livewire::test(ListBranches::class)
        ->callTableAction('make_default', $other)
        ->assertNotified();

    expect(Branch::default()?->id)->toBe($other->id)
        ->and($this->branch->fresh()->is_default)->toBeFalse();
});

it('refuses to deactivate the default branch', function () {
    expect(fn () => app(BranchService::class)->setActive($this->branch, false, $this->admin))
        ->toThrow(DomainException::class)
        ->and($this->branch->fresh()->is_active)->toBeTrue();
});

it('deactivates and reactivates a non-default branch', function () {
    $other = Branch::factory()->create();
    $service = app(BranchService::class);

    $service->setActive($other, false, $this->admin);
    expect($other->fresh()->is_active)->toBeFalse();

    $service->setActive($other, true, $this->admin);
    expect($other->fresh()->is_active)->toBeTrue();
});

it('hides make-default and deactivate on the default branch row', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListBranches::class)
        ->assertTableActionHidden('set_active', record: $this->branch)
        ->assertTableActionHidden('make_default', record: $this->branch);
});

it('hides make-default on an inactive branch', function () {
    $this->actingAs($this->admin);
    $inactive = Branch::factory()->inactive()->create();

    // The default TernaryFilter hides inactive rows; surface them to assert on them.
    Livewire::test(ListBranches::class)
        ->filterTable('is_active', false)
        ->assertTableActionHidden('make_default', record: $inactive)
        ->assertTableActionVisible('set_active', record: $inactive);
});

it('refuses to delete the default branch', function () {
    expect(fn () => app(BranchService::class)->delete($this->branch, $this->admin))
        ->toThrow(DomainException::class)
        ->and(Branch::find($this->branch->id))->not->toBeNull();
});

it('refuses to delete a branch that still has rows pointing at it', function () {
    $other = Branch::factory()->create();
    User::factory()->create(['branch_id' => $other->id]);

    expect(fn () => app(BranchService::class)->delete($other, $this->admin))
        ->toThrow(DomainException::class)
        ->and(Branch::find($other->id))->not->toBeNull();
});

it('soft-deletes a branch that is neither default nor referenced', function () {
    $other = Branch::factory()->create();

    app(BranchService::class)->delete($other, $this->admin);

    expect(Branch::find($other->id))->toBeNull()
        ->and(Branch::withTrashed()->find($other->id))->not->toBeNull();
});

it('hides delete on the default branch edit page and guards it for referenced branches', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditBranch::class, ['record' => $this->branch->getKey()])
        ->assertActionHidden('delete');

    $other = Branch::factory()->create();
    User::factory()->create(['branch_id' => $other->id]);

    Livewire::test(EditBranch::class, ['record' => $other->getKey()])
        ->callAction('delete')
        ->assertNotified();

    expect(Branch::withTrashed()->find($other->id))->not->toBeNull();
});

it('lists the branch staff read-only', function () {
    $this->actingAs($this->manager);
    $staff = User::factory()->create(['branch_id' => $this->branch->id]);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->branch,
        'pageClass' => EditBranch::class,
    ])
        ->assertCanSeeTableRecords([$staff]);
});

it('gates the staff list behind users.manage', function () {
    expect(UsersRelationManager::canViewForRecord($this->branch, EditBranch::class))->toBeFalse();

    $this->actingAs($this->manager);

    expect(UsersRelationManager::canViewForRecord($this->branch, EditBranch::class))->toBeTrue();
});

it('exposes all 58 wilayas', function () {
    expect(Wilaya::cases())->toHaveCount(58);
});

it('exposes the wilaya select on the branch form', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateBranch::class)->assertFormFieldExists('wilaya');
});
