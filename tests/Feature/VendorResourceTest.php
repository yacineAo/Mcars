<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\VendorResource;
use App\Filament\Admin\Resources\VendorResource\Pages\CreateVendor;
use App\Filament\Admin\Resources\VendorResource\Pages\EditVendor;
use App\Filament\Admin\Resources\VendorResource\Pages\ViewVendor;
use App\Filament\Admin\Resources\VendorResource\RelationManagers\ExpensesRelationManager;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true]);
    $this->seed(RolePermissionSeeder::class);
});

it('persists bank, RIB and CCP details through the create form', function () {
    $manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $manager->assignRole(UserRole::Manager->value);
    Auth::login($manager);

    Livewire::test(CreateVendor::class)
        ->fillForm([
            'name' => 'Garage Es-Salam',
            'type' => 'garage',
            'bank_account_number' => '00799999000123456789',
            'rib' => '007 999 99000123456789 12',
            'ccp_number' => '1234567 89',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $vendor = Vendor::where('name', 'Garage Es-Salam')->firstOrFail();

    expect($vendor->bank_account_number)->toBe('00799999000123456789')
        ->and($vendor->rib)->toBe('007 999 99000123456789 12')
        ->and($vendor->ccp_number)->toBe('1234567 89');
});

it('shows the Payment Details section to a manager who holds reports.view_financials', function () {
    $manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $manager->assignRole(UserRole::Manager->value);
    Auth::login($manager);

    $vendor = Vendor::factory()->create();

    Livewire::test(EditVendor::class, ['record' => $vendor->getKey()])
        ->assertFormFieldExists('rib');
});

it('refuses fleet.manage to a receptionist, so the edit form (and its Payment Details section) is unreachable', function () {
    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole(UserRole::Receptionist->value);
    Auth::login($receptionist);

    expect(VendorResource::canEdit(Vendor::factory()->create()))->toBeFalse();
});

/**
 * A vendor can be shared across branches (a fuel station, an insurer), so its
 * expense history can span branches the viewer cannot otherwise reach —
 * `reports.view_financials` (held by the accountant) does not imply
 * `branches.view_all`.
 */
it('pins the vendor expenses relation manager to the branches the user can reach', function () {
    $theirs = Branch::factory()->create(['code' => 'OULED']);

    $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $accountant->assignRole(UserRole::Accountant->value);

    $vendor = Vendor::factory()->create();
    $category = ExpenseCategory::factory()->create();

    $myExpense = Expense::create([
        'branch_id' => $this->branch->id,
        'expense_category_id' => $category->id,
        'vendor_id' => $vendor->id,
        'amount' => '100.00',
        'total_amount' => '100.00',
        'incurred_on' => '2026-07-10',
        'status' => ExpenseStatus::Approved,
    ]);
    $theirExpense = Expense::create([
        'branch_id' => $theirs->id,
        'expense_category_id' => $category->id,
        'vendor_id' => $vendor->id,
        'amount' => '200.00',
        'total_amount' => '200.00',
        'incurred_on' => '2026-07-11',
        'status' => ExpenseStatus::Approved,
    ]);

    Auth::login($accountant);

    Livewire::test(ExpensesRelationManager::class, [
        'ownerRecord' => $vendor,
        'pageClass' => ViewVendor::class,
    ])
        ->assertCanSeeTableRecords([$myExpense])
        ->assertCanNotSeeTableRecords([$theirExpense]);
});
