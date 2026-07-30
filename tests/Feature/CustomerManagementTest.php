<?php

declare(strict_types=1);

use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\Customer\CustomerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an individual customer', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Ahmed',
        'last_name' => 'Benali',
        'type' => 'individual',
    ]);

    expect($customer->code)->toStartWith('IND-');
});

it('creates a company customer', function () {
    $customer = Customer::factory()->create([
        'company_name' => 'SARL Auto Plus',
        'type' => 'company',
    ]);

    expect($customer->code)->toStartWith('COM-');
});

it('detects duplicate national id', function () {
    Customer::factory()->create(['national_id' => '123456789']);
    expect(fn () => Customer::factory()->create(['national_id' => '123456789']))
        ->toThrow(QueryException::class);
});

it('detects duplicate phone', function () {
    Customer::factory()->create(['phone' => '+213-555-1234']);
    expect(fn () => Customer::factory()->create(['phone' => '+213-555-1234']))
        ->toThrow(QueryException::class);
});

it('detects duplicate driving license number', function () {
    Customer::factory()->create(['driving_license_number' => 'LIC-1234567']);
    expect(fn () => Customer::factory()->create(['driving_license_number' => 'LIC-1234567']))
        ->toThrow(QueryException::class);
});

it('creates customer document', function () {
    $customer = Customer::factory()->create();
    $doc = CustomerDocument::factory()->create([
        'customer_id' => $customer->id,
        'type' => CustomerDocumentType::DrivingLicense,
        'expiry_date' => now()->addYear(),
    ]);

    expect($doc->customer->id)->toBe($customer->id);
    expect($doc->expiry_date->isFuture())->toBeTrue();
});

it('can blacklist a customer', function () {
    $customer = Customer::factory()->create([
        'is_blacklisted' => true,
        'blacklist_reason' => 'Damaged previous rental car',
    ]);

    expect($customer->is_blacklisted)->toBeTrue();
    expect($customer->blacklist_reason)->toBe('Damaged previous rental car');
});

it('toggles blacklist on via service', function () {
    $customer = Customer::factory()->create(['is_blacklisted' => false]);

    $service = app(CustomerService::class);
    $result = $service->toggleBlacklist($customer, 'Fraudulent ID');

    expect($result->is_blacklisted)->toBeTrue();
    expect($result->blacklist_reason)->toBe('Fraudulent ID');
    expect($result->blacklisted_at)->not->toBeNull();
});

it('toggles blacklist off via service', function () {
    $customer = Customer::factory()->create([
        'is_blacklisted' => true,
        'blacklist_reason' => 'Previous damage',
        'blacklisted_at' => now()->subDay(),
    ]);

    $service = app(CustomerService::class);
    $result = $service->toggleBlacklist($customer);

    expect($result->is_blacklisted)->toBeFalse();
    expect($result->blacklist_reason)->toBeNull();
    expect($result->blacklisted_at)->toBeNull();
});

it('toggles blacklist with null reason', function () {
    $customer = Customer::factory()->create(['is_blacklisted' => false]);

    $service = app(CustomerService::class);
    $result = $service->toggleBlacklist($customer, null);

    expect($result->is_blacklisted)->toBeTrue();
    expect($result->blacklist_reason)->toBeNull();
    expect($result->blacklisted_at)->not->toBeNull();
});

it('rejects rating outside 1-5 range at db level', function () {
    $customer = Customer::factory()->make(['rating' => 6]);
    expect(fn () => $customer->save())
        ->toThrow(QueryException::class);
});

it('verifies a document', function () {
    $doc = CustomerDocument::factory()->create(['verified_at' => null]);
    expect($doc->verified_at)->toBeNull();

    $doc->update(['verified_at' => now(), 'verified_by_id' => null]);
    expect($doc->fresh()->verified_at)->not->toBeNull();
});
