<?php

declare(strict_types=1);

use App\Enums\AgreementStatus;
use App\Enums\CarDocumentType;
use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDocument;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\Vendor;
use App\Services\Fleet\CancelMaintenanceService;
use App\Services\Fleet\LogMaintenanceService;
use App\Services\Fleet\StartMaintenanceService;
use App\Services\FleetStatusService;
use App\Services\OwnerAgreementService;
use Carbon\CarbonImmutable;
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

// ---------------------------------------------------------------------------
// LogMaintenanceService
// ---------------------------------------------------------------------------

it('logs a maintenance service from a schedule', function () {
    $schedule = MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    $log = app(LogMaintenanceService::class)->log(
        schedule: $schedule,
        scheduledFor: CarbonImmutable::tomorrow(),
    );

    expect($log->car_id)->toBe($this->car->id)
        ->and($log->type)->toBe(MaintenanceType::OilChange)
        ->and($log->status)->toBe(MaintenanceStatus::Scheduled)
        ->and($log->branch_id)->toBe($this->car->branch_id);
});

it('refuses to create a duplicate open log for the same car and task', function () {
    $schedule = MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    app(LogMaintenanceService::class)->log(
        schedule: $schedule,
        scheduledFor: CarbonImmutable::tomorrow(),
    );

    expect(fn () => app(LogMaintenanceService::class)->log(
        schedule: $schedule,
        scheduledFor: CarbonImmutable::tomorrow()->addDay(),
    ))->toThrow(RuntimeException::class, 'open');
});

it('refuses to log a service for a category-level schedule with no car_id', function () {
    $category = CarCategory::factory()->create();

    $schedule = MaintenanceSchedule::create([
        'car_category_id' => $category->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    expect(fn () => app(LogMaintenanceService::class)->log(
        schedule: $schedule,
        scheduledFor: CarbonImmutable::tomorrow(),
    ))->toThrow(RuntimeException::class, 'category-level');
});

it('sets branch_id from the car, not null', function () {
    $branch = Branch::factory()->create(['code' => 'ORAN', 'is_default' => false]);
    $car = Car::factory()->create(['status' => CarStatus::Available, 'branch_id' => $branch->id]);

    $schedule = MaintenanceSchedule::create([
        'car_id' => $car->id,
        'task_type' => MaintenanceType::TireChange,
        'interval_km' => 20_000,
        'is_active' => true,
    ]);

    $log = app(LogMaintenanceService::class)->log(
        schedule: $schedule,
        scheduledFor: CarbonImmutable::tomorrow(),
    );

    expect($log->branch_id)->toBe($branch->id);
});

// ---------------------------------------------------------------------------
// Unique constraint on (car_id, task_type)
// ---------------------------------------------------------------------------

it('prevents duplicate active schedules for the same car and task', function () {
    MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    expect(fn () => MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 5_000,
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

it('allows the same task for different cars', function () {
    $car2 = Car::factory()->create(['status' => CarStatus::Available]);

    MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    $result = MaintenanceSchedule::create([
        'car_id' => $car2->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'is_active' => true,
    ]);

    expect($result->exists)->toBeTrue();
});

// ---------------------------------------------------------------------------
// StartMaintenanceService
// ---------------------------------------------------------------------------

it('starts a scheduled maintenance service and transitions the car to maintenance', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Scheduled,
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    $result = app(StartMaintenanceService::class)->start($log);

    expect($result->status)->toBe(MaintenanceStatus::InProgress)
        ->and($result->started_at)->not->toBeNull()
        ->and($this->car->fresh()->status)->toBe(CarStatus::Maintenance);
});

it('refuses to start a non-scheduled maintenance log', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::Repair,
        'status' => MaintenanceStatus::InProgress,
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    expect(fn () => app(StartMaintenanceService::class)->start($log))
        ->toThrow(RuntimeException::class, 'cannot be started');
});

it('does not transition a rented car when starting maintenance', function () {
    $this->car->update(['status' => CarStatus::Rented]);

    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Scheduled,
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    $result = app(StartMaintenanceService::class)->start($log);

    expect($result->status)->toBe(MaintenanceStatus::InProgress)
        ->and($this->car->fresh()->status)->toBe(CarStatus::Rented);
});

// ---------------------------------------------------------------------------
// CancelMaintenanceService
// ---------------------------------------------------------------------------

it('cancels a scheduled maintenance log', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Scheduled,
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    $result = app(CancelMaintenanceService::class)->cancel($log);

    expect($result->status)->toBe(MaintenanceStatus::Cancelled);
});

it('cancels an in-progress maintenance log and returns the car to available', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::InProgress,
        'started_at' => CarbonImmutable::now(),
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    // Put the car in maintenance as start_service would.
    $this->car->update(['status' => CarStatus::Maintenance]);

    $result = app(CancelMaintenanceService::class)->cancel($log);

    expect($result->status)->toBe(MaintenanceStatus::Cancelled)
        ->and($this->car->fresh()->status)->toBe(CarStatus::Available);
});

it('refuses to cancel a completed maintenance log', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Completed,
        'completed_at' => CarbonImmutable::yesterday(),
        'scheduled_for' => CarbonImmutable::yesterday()->subDay(),
    ]);

    expect(fn () => app(CancelMaintenanceService::class)->cancel($log))
        ->toThrow(RuntimeException::class, 'cannot be cancelled');
});

it('refuses to cancel an already-cancelled maintenance log', function () {
    $log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->car->branch_id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Cancelled,
        'scheduled_for' => CarbonImmutable::yesterday(),
    ]);

    expect(fn () => app(CancelMaintenanceService::class)->cancel($log))
        ->toThrow(RuntimeException::class, 'cannot be cancelled');
});
