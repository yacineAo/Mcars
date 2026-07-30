<?php

declare(strict_types=1);

use App\Enums\AgreementStatus;
use App\Enums\CarDocumentType;
use App\Enums\CarStatus;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDocument;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\Vendor;
use App\Services\FleetStatusService;
use App\Services\OwnerAgreementService;
use Database\Seeders\CarCategorySeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->car = Car::factory()->create(['status' => CarStatus::Available]);
    $this->service = app(FleetStatusService::class);
});

it('seeds car categories', function () {
    $this->seed(CarCategorySeeder::class);
    expect(CarCategory::count())->toBeGreaterThanOrEqual(5);
});

it('seeds vendors', function () {
    $this->seed(VendorSeeder::class);
    expect(Vendor::count())->toBeGreaterThanOrEqual(3);
});

it('transitions available to reserved', function () {
    $this->service->transition($this->car, CarStatus::Reserved);
    expect($this->car->fresh()->status)->toBe(CarStatus::Reserved);
});

it('transitions reserved to rented', function () {
    $this->car->update(['status' => CarStatus::Reserved]);
    $this->service->transition($this->car, CarStatus::Rented);
    expect($this->car->fresh()->status)->toBe(CarStatus::Rented);
});

it('rejects illegal transition from rented to sold', function () {
    $this->car->update(['status' => CarStatus::Rented]);
    $this->service->transition($this->car, CarStatus::Sold);
})->throws(InvalidArgumentException::class);

it('rejects illegal transition from available to rented', function () {
    $this->car->update(['status' => CarStatus::Available]);
    $this->service->transition($this->car, CarStatus::Rented);
})->throws(InvalidArgumentException::class);

it('allows transition from maintenance to available', function () {
    $this->car->update(['status' => CarStatus::Maintenance]);
    $this->service->transition($this->car, CarStatus::Available);
    expect($this->car->fresh()->status)->toBe(CarStatus::Available);
});

it('rejects transition from sold to anything', function () {
    $this->car->update(['status' => CarStatus::Sold]);
    expect(fn () => $this->service->transition($this->car, CarStatus::Available))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects terminal status with active agreements', function () {
    $owner = CarOwner::factory()->create();
    $agreement = CarOwnershipAgreement::factory()->create([
        'car_id' => $this->car->id,
        'car_owner_id' => $owner->id,
        'status' => AgreementStatus::Active,
        'start_date' => now()->subMonth(),
        'end_date' => null,
    ]);
    $this->car->update(['status' => CarStatus::Available]);
    expect(fn () => $this->service->transition($this->car, CarStatus::Sold))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects overlapping active ownership agreements for the same car', function () {
    $owner = CarOwner::factory()->create();
    $agreement1 = CarOwnershipAgreement::factory()->create([
        'car_id' => $this->car->id,
        'car_owner_id' => $owner->id,
        'status' => AgreementStatus::Active,
        'start_date' => now()->subYear(),
        'end_date' => null,
    ]);
    expect(fn () => CarOwnershipAgreement::factory()->create([
        'car_id' => $this->car->id,
        'car_owner_id' => $owner->id,
        'status' => AgreementStatus::Active,
        'start_date' => now()->subMonth(),
        'end_date' => null,
    ]))->toThrow(QueryException::class);
});

it('returns a validation error when activating an overlapping draft', function () {
    $owner = CarOwner::factory()->create();
    $service = app(OwnerAgreementService::class);

    // Has an existing active agreement, so activating this draft should fail.
    CarOwnershipAgreement::factory()->create([
        'car_id' => $this->car->id,
        'car_owner_id' => $owner->id,
        'status' => AgreementStatus::Active,
        'start_date' => now()->subYear(),
        'end_date' => null,
    ]);

    $draft = CarOwnershipAgreement::factory()->create([
        'car_id' => $this->car->id,
        'car_owner_id' => $owner->id,
        'status' => AgreementStatus::Draft,
        'start_date' => now()->subMonth(),
        'end_date' => null,
    ]);

    expect(fn () => $service->activate($draft))
        ->toThrow(ValidationException::class, 'active agreement');
});

it('syncs insurance expiry date on car when document is created', function () {
    $expiry = now()->addYear()->toDateString();
    $doc = CarDocument::factory()->create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'expiry_date' => $expiry,
    ]);
    expect($this->car->fresh()->insurance_expiry_date->toDateString())->toBe($expiry);
});

it('syncs latest insurance expiry when the most recent document is updated', function () {
    $oldExpiry = now()->addMonth()->toDateString();
    $newExpiry = now()->addYear()->toDateString();

    $doc1 = CarDocument::factory()->create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'expiry_date' => $oldExpiry,
    ]);
    $doc2 = CarDocument::factory()->create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'expiry_date' => $newExpiry,
    ]);

    expect($this->car->fresh()->insurance_expiry_date->toDateString())->toBe($newExpiry);
});

it('updates expiry mirror when a document is deleted', function () {
    $expiry = now()->addYear()->toDateString();
    $doc = CarDocument::factory()->create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::TechnicalInspection,
        'expiry_date' => $expiry,
    ]);

    expect($this->car->fresh()->technical_inspection_expiry_date->toDateString())->toBe($expiry);

    $doc->delete();
    expect($this->car->fresh()->technical_inspection_expiry_date)->toBeNull();
});

it('provides allowed transitions for a given status', function () {
    $car = Car::factory()->create(['status' => CarStatus::Available]);
    $allowed = $this->service->allowedTransitions($car);
    expect($allowed)->toContain('reserved', 'maintenance', 'out_of_service', 'sold', 'returned_to_owner');
    expect($allowed)->not->toContain('rented');
});
