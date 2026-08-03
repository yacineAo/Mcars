<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\CreateCarOwner;
use App\Models\Branch;
use App\Models\CarOwner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = Branch::factory()->create(['is_default' => true]);
    $this->seed(RolePermissionSeeder::class);

    $this->manager = User::factory()->create(['branch_id' => $branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);
    Auth::login($this->manager);
});

it('detects a duplicate national_id at the database level', function () {
    CarOwner::factory()->create(['national_id' => '123456789']);

    expect(fn () => CarOwner::factory()->create(['national_id' => '123456789']))
        ->toThrow(QueryException::class);
});

it('allows two owners with no national_id', function () {
    CarOwner::factory()->create(['national_id' => null]);
    CarOwner::factory()->create(['national_id' => null]);

    expect(CarOwner::whereNull('national_id')->count())->toBe(2);
});

it('shows a field error instead of a database error on a duplicate national_id in the create form', function () {
    CarOwner::factory()->create(['national_id' => '123456789']);

    Livewire::test(CreateCarOwner::class)
        ->fillForm([
            'type' => 'individual',
            'first_name' => 'Ahmed',
            'last_name' => 'Benali',
            'phone' => '0555000000',
            'national_id' => '123456789',
        ])
        ->call('create')
        ->assertHasFormErrors(['national_id' => 'unique']);
});
