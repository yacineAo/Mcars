<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\CarDocumentResource;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\ListCarDocuments;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = Branch::factory()->create(['is_default' => true]);
    $this->seed(RolePermissionSeeder::class);

    $this->manager = User::factory()->create(['branch_id' => $branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);
    Auth::login($this->manager);
});

it('canAccess returns true for a user with fleet.view', function () {
    expect(CarDocumentResource::canAccess())->toBeTrue();
});

it('canAccess returns false for a user without fleet.view', function () {
    $user = User::factory()->create();
    Auth::login($user);

    expect(CarDocumentResource::canAccess())->toBeFalse();
});

it('the list page renders for an authorised user', function () {
    $this->get(CarDocumentResource::getUrl('index'))->assertSuccessful();
});

it('eager-loads car and media so a page of documents does not scale per row', function () {
    $category = CarCategory::factory()->create();

    $touchARowOfDocuments = function (int $carCount) use ($category): int {
        $cars = Car::factory()->count($carCount)->create(['car_category_id' => $category->id]);
        foreach ($cars as $car) {
            CarDocument::factory()->create(['car_id' => $car->id]);
        }

        $table = Livewire::test(ListCarDocuments::class)->instance()->getTable();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $table->getQuery()->get()->each(function (CarDocument $document): void {
            // Touching the relations a rendered index column and the preview-scan
            // action both read — if either lazy-loads, the query log grows with N.
            $document->car?->registration_number;
            $document->getFirstMedia('document');
        });

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Same three queries (documents, car, media) whether the page holds 3 rows
    // or 11 — one eager-load batch each, not one lookup per row.
    expect($touchARowOfDocuments(3))->toBe(3)
        ->and($touchARowOfDocuments(8))->toBe(3);
});
