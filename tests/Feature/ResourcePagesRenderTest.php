<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ContractStatus;
use App\Enums\DepositStatus;
use App\Enums\ExpenseStatus;
use App\Enums\FineLiability;
use App\Enums\FineType;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\CashSession;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Fine;
use App\Models\MaintenanceLog;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Every registered resource must actually render, **with a row in it**.
 *
 * BookingResource and ContractResource were both shipping a 500: each used
 * `->color(fn (SomeEnum $s) => ...)` on a badge column, which makes Filament
 * resolve the enum from the service container. The bookings list — the busiest
 * screen in the system — was simply broken.
 *
 * The row matters. Filament evaluates column closures lazily, so against empty
 * tables the broken closure never fires and the page renders fine. An earlier
 * version of this test passed happily with the bug reintroduced, which is worse
 * than no test at all. Each resource below therefore gets at least one record
 * before its page is opened.
 */
beforeEach(function () {
    // The branch must exist first: FinancialAccountSeeder attaches its accounts
    // to the default branch and quietly creates nothing without one.
    $branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);
    $this->seed(FinancialAccountSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $car = Car::factory()->create(['branch_id' => $branch->id, 'daily_rate' => '5000.00']);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $owner = CarOwner::factory()->create(['branch_id' => $branch->id]);
    $account = FinancialAccount::query()->firstOrFail();
    $category = ExpenseCategory::query()->firstOrFail();

    $booking = Booking::create([
        'uuid' => (string) Str::uuid(),
        'reference' => 'BK-RENDER',
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(2),
        'expected_return_at' => now()->addDays(2),
        'actual_pickup_at' => now()->subDays(2),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'total_amount' => '20000.00',
        'created_by_id' => $this->admin->id,
    ]);

    Contract::create([
        'uuid' => (string) Str::uuid(),
        'contract_number' => 'CTR-RENDER',
        'branch_id' => $branch->id,
        'booking_id' => $booking->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => ContractStatus::Active,
        'content_snapshot' => ['rendered' => true],
        'has_damages' => false,
    ]);

    ContractTemplate::create([
        'branch_id' => $branch->id,
        'name' => 'Render template',
        'locale' => 'fr',
        'body' => "Rental agreement\n\n{{customer_name}}",
        'terms_version' => '1.0',
        'is_active' => true,
        'is_default' => true,
    ]);

    Payment::create([
        'reference' => 'PAY-RENDER',
        'branch_id' => $branch->id,
        'direction' => 'inbound',
        'customer_id' => $customer->id,
        'method' => PaymentMethod::Cash,
        'amount' => '5000.00',
        'paid_at' => now(),
        'financial_account_id' => $account->id,
        'status' => 'completed',
    ]);

    Deposit::create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'amount' => '30000.00',
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
    ]);

    Fine::create([
        'reference' => 'FIN-RENDER',
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'type' => FineType::Speeding,
        'violation_at' => now()->subDay(),
        'received_at' => now(),
        'amount' => '5000.00',
        'late_penalty_amount' => '0.00',
        'total_amount' => '5000.00',
        'liability' => FineLiability::PendingReview,
        'status' => 'new',
    ]);

    Expense::create([
        'reference' => 'EXP-RENDER',
        'branch_id' => $branch->id,
        'expense_category_id' => $category->id,
        'amount' => '4000.00',
        'total_amount' => '4000.00',
        'incurred_on' => now(),
        'status' => ExpenseStatus::Approved,
    ]);

    $employee = Employee::create([
        'branch_id' => $branch->id,
        'employee_number' => 'EMP-RENDER',
        'first_name' => 'Render',
        'last_name' => 'Test',
        'hire_date' => now()->subYear(),
        'base_salary' => '50000.00',
    ]);

    PayrollRun::create([
        'branch_id' => $branch->id,
        'period_month' => now()->startOfMonth(),
        'status' => PayrollStatus::Draft,
    ]);

    $agreement = CarOwnershipAgreement::factory()->create([
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'car_owner_id' => $owner->id,
    ]);

    OwnerInstallment::create([
        'car_ownership_agreement_id' => $agreement->id,
        'car_owner_id' => $owner->id,
        'car_id' => $car->id,
        'branch_id' => $branch->id,
        'sequence_number' => 1,
        'total_installments' => 12,
        'period_month' => now()->startOfMonth(),
        'due_date' => now()->addDays(5),
        'amount_due' => '60000.00',
        'status' => 'pending',
    ]);

    CashSession::create([
        'branch_id' => $branch->id,
        'financial_account_id' => $account->id,
        'opened_by_id' => $this->admin->id,
        'opened_at' => now(),
        'opening_float' => '10000.00',
        'status' => 'open',
    ]);

    MaintenanceLog::create([
        'car_id' => $car->id,
        'branch_id' => $branch->id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::Completed,
        'completed_at' => now()->subDay(),
        'total_cost' => '8000.00',
    ]);

    unset($employee);
});

it('renders the dashboard', function () {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertSuccessful();
});

it('renders the index page of every resource, with data in it', function () {
    $this->actingAs($this->admin);

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! isset($resource::getPages()['index'])) {
            continue;
        }

        try {
            $status = $this->get($resource::getUrl('index', panel: 'admin'))->getStatusCode();

            if ($status >= 400) {
                $failures[] = class_basename($resource).' → HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $failures[] = class_basename($resource).' → '.mb_substr($e->getMessage(), 0, 140);
        }
    }

    expect($failures)->toBe([]);
});

it('renders the create page of every resource that has one', function () {
    $this->actingAs($this->admin);

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! isset($resource::getPages()['create']) || ! $resource::canCreate()) {
            continue;
        }

        try {
            $status = $this->get($resource::getUrl('create', panel: 'admin'))->getStatusCode();

            if ($status >= 400) {
                $failures[] = class_basename($resource).' → HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $failures[] = class_basename($resource).' → '.mb_substr($e->getMessage(), 0, 140);
        }
    }

    expect($failures)->toBe([]);
});

it('renders the view page of every resource that has one, with a row in it', function () {
    $this->actingAs($this->admin);

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! isset($resource::getPages()['view'])) {
            continue;
        }

        $record = $resource::getModel()::query()->first();

        if ($record === null) {
            continue;
        }

        try {
            $status = $this->get($resource::getUrl('view', ['record' => $record], panel: 'admin'))->getStatusCode();

            if ($status >= 400) {
                $failures[] = class_basename($resource).' → HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $failures[] = class_basename($resource).' → '.mb_substr($e->getMessage(), 0, 140);
        }
    }

    expect($failures)->toBe([]);
});
