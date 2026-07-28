<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CarStatus;
use App\Enums\TransactionType;
use App\Events\TransactionPosted;
use App\Filament\Admin\Widgets\CashFlowChart;
use App\Filament\Admin\Widgets\DailyOverviewStats;
use App\Filament\Admin\Widgets\DueReturnsTodayTable;
use App\Filament\Admin\Widgets\ExpenseBreakdownChart;
use App\Filament\Admin\Widgets\FleetOccupancyGauge;
use App\Filament\Admin\Widgets\MonthlyRevenueExpenseChart;
use App\Filament\Admin\Widgets\NetProfitTrendChart;
use App\Filament\Admin\Widgets\OverdueReturnsTable;
use App\Filament\Admin\Widgets\ReceivablesAgeingWidget;
use App\Filament\Admin\Widgets\TopCarsByProfitTable;
use App\Filament\Admin\Widgets\TopCustomersTable;
use App\Filament\Admin\Widgets\UpcomingPickupsTable;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * getStats() is a protected template method on Filament's StatsOverviewWidget.
 * Reaching it directly keeps these assertions on the widget's own logic instead of
 * booting a full Livewire render just to read back a label.
 *
 * @return Collection<int, Stat>
 */
function widgetStats(object $widget): Collection
{
    $method = new \ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    return collect($method->invoke($widget));
}

/**
 * @return Collection<int, string|null>
 */
function statLabels(object $widget): Collection
{
    return widgetStats($widget)->map(fn ($stat) => $stat->getLabel());
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->assignRole('manager');

    $this->accounting = app(AccountingService::class);
    $this->reportService = app(ReportService::class);

    $this->account1010 = ChartOfAccount::where('code', '1010')->firstOrFail(); // Cash on hand
    $this->account1020 = ChartOfAccount::where('code', '1020')->firstOrFail(); // Bank
    $this->account1110 = ChartOfAccount::where('code', '1110')->firstOrFail(); // AR – customers
    $this->account4010 = ChartOfAccount::where('code', '4010')->firstOrFail(); // Rental revenue
    $this->account5010 = ChartOfAccount::where('code', '5010')->firstOrFail(); // Fuel expense

    $this->post = function (array $overrides = []) {
        return $this->accounting->post(new TransactionDraft(...[
            'debitAccountId' => $this->account1010->id,
            'creditAccountId' => $this->account4010->id,
            'amount' => '10000.00',
            'type' => TransactionType::RentalRevenue,
            'occurredOn' => new DateTimeImmutable,
            'branchId' => $this->branch->id,
            'createdById' => $this->user->id,
            ...$overrides,
        ]));
    };
});

it('calculates daily and monthly KPIs correctly', function () {
    $car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => CarStatus::Available->value,
    ]);

    // Post 20,000 revenue
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account1010->id,
        creditAccountId: $this->account4010->id,
        amount: '20000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        carId: $car->id,
        createdById: $this->user->id,
    ));

    // Post 5,000 fuel expense
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account5010->id,
        creditAccountId: $this->account1010->id,
        amount: '5000.00',
        type: TransactionType::Fuel,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        carId: $car->id,
        createdById: $this->user->id,
    ));

    $kpis = $this->reportService->dailyKpis($this->branch->id);

    expect($kpis['daily_revenue'])->toBe(20000.0)
        ->and($kpis['daily_expenses'])->toBe(5000.0)
        ->and($kpis['daily_net_profit'])->toBe(15000.0)
        ->and($kpis['cash_on_hand'])->toBe(15000.0);
});

it('computes per-car profitability accurately', function () {
    $carA = Car::factory()->create(['branch_id' => $this->branch->id]);
    $carB = Car::factory()->create(['branch_id' => $this->branch->id]);

    // Car A: 30,000 revenue, 5,000 expense -> 25,000 net profit
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account1010->id,
        creditAccountId: $this->account4010->id,
        amount: '30000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        carId: $carA->id,
        createdById: $this->user->id,
    ));

    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account5010->id,
        creditAccountId: $this->account1010->id,
        amount: '5000.00',
        type: TransactionType::Fuel,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        carId: $carA->id,
        createdById: $this->user->id,
    ));

    $today = CarbonImmutable::today();
    $profitability = $this->reportService->carProfitability($today->startOfMonth(), $today->endOfMonth(), $this->branch->id);

    $carAData = collect($profitability)->firstWhere('car_id', $carA->id);
    expect($carAData['revenue'])->toBe(30000.0)
        ->and($carAData['expenses'])->toBe(5000.0)
        ->and($carAData['net_profit'])->toBe(25000.0);
});

it('excludes cash-to-cash internal transfers from cash flow calculations', function () {
    // Revenue of 50,000 (Cash In)
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account1010->id,
        creditAccountId: $this->account4010->id,
        amount: '50000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        createdById: $this->user->id,
    ));

    // Internal Transfer: 20,000 from Cash (1010) to Bank (1020) — both cash equivalents
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account1020->id,
        creditAccountId: $this->account1010->id,
        amount: '20000.00',
        type: TransactionType::CashTransfer,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        createdById: $this->user->id,
    ));

    $today = CarbonImmutable::today();
    $cashFlow = $this->reportService->cashFlow($today->startOfMonth(), $today->endOfMonth(), $this->branch->id);

    // Cash In should be 50,000 and Cash Out 0 (internal transfer excluded)
    expect($cashFlow['cash_in'])->toBe(50000.0)
        ->and($cashFlow['cash_out'])->toBe(0.0);
});

it('dispatches TransactionPosted event and flushes report cache when transaction is posted', function () {
    Event::fake([TransactionPosted::class]);

    $this->accounting->post(new TransactionDraft(
        debitAccountId: $this->account1010->id,
        creditAccountId: $this->account4010->id,
        amount: '10000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        branchId: $this->branch->id,
        createdById: $this->user->id,
    ));

    Event::assertDispatched(TransactionPosted::class);
});

it('restricts financial widgets visibility for receptionist role', function () {
    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole('receptionist');

    $this->actingAs($receptionist);

    expect(MonthlyRevenueExpenseChart::canView())->toBeFalse()
        ->and(NetProfitTrendChart::canView())->toBeFalse();

    $this->actingAs($this->user); // manager role

    expect(MonthlyRevenueExpenseChart::canView())->toBeTrue()
        ->and(NetProfitTrendChart::canView())->toBeTrue();
});

it('provides customer statement with invoiced, paid, owed, and deposits', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $stmt = $this->reportService->customerStatement($customer->id);

    expect($stmt)->toHaveKeys(['customer_id', 'invoiced', 'paid', 'owed', 'deposits_held', 'active_fines_count'])
        ->and($stmt['customer_id'])->toBe($customer->id);
});

it('reduces revenue when a posting is reversed rather than inflating expenses', function () {
    $transaction = ($this->post)(['amount' => '18000.00']);

    $today = CarbonImmutable::today();
    $before = $this->reportService->profitAndLoss($today->startOfDay(), $today->endOfDay(), $this->branch->id);
    expect($before['revenue'])->toBe(18000.0);

    $this->accounting->reverse($transaction, 'Booking cancelled', $this->user);

    $after = $this->reportService->profitAndLoss($today->startOfDay(), $today->endOfDay(), $this->branch->id);

    // The reversal debits revenue. It must cancel the revenue, not appear as expense.
    expect($after['revenue'])->toBe(0.0)
        ->and($after['expenses'])->toBe(0.0)
        ->and($after['net_profit'])->toBe(0.0);
});

it('computes occupancy against a hand-calculated fixture', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $carA = Car::factory()->create(['branch_id' => $this->branch->id]);
    Car::factory()->create(['branch_id' => $this->branch->id]); // idle all period

    // A 10-day window; car A is on rent for exactly 4 of those days.
    $from = CarbonImmutable::parse('2026-03-01 00:00:00');
    $to = CarbonImmutable::parse('2026-03-10 23:59:59');

    Booking::create([
        'branch_id' => $this->branch->id,
        'pickup_branch_id' => $this->branch->id,
        'car_id' => $carA->id,
        'customer_id' => $customer->id,
        'status' => 'completed',
        'pickup_at' => '2026-03-02 08:00:00',
        'expected_return_at' => '2026-03-06 08:00:00',
        'actual_pickup_at' => '2026-03-02 08:00:00',
        'actual_return_at' => '2026-03-06 08:00:00',
        'daily_rate' => 5000.00,
        'days_count' => 4,
        'subtotal' => 20000.00,
        'total_amount' => 20000.00,
        'created_by_id' => $this->user->id,
    ]);

    // 4 rented car-days ÷ (2 cars × 10 calendar days) = 20%
    expect($this->reportService->occupancyRate($from, $to, $this->branch->id))->toBe(20.0);

    // Per-car: 4 rented days ÷ 10 calendar days = 40%. Denominator is NOT
    // availability-adjusted — see the ReportService docblock.
    $carRow = collect($this->reportService->carProfitability($from, $to, $this->branch->id))
        ->firstWhere('car_id', $carA->id);

    expect($carRow['rental_days'])->toBe(4.0)
        ->and($carRow['utilisation_pct'])->toBe(40.0);
});

it('clips bookings that overrun the reporting period', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);

    // Booking spans 28 Feb → 5 Mar, but the period is only 1–10 Mar.
    Booking::create([
        'branch_id' => $this->branch->id,
        'pickup_branch_id' => $this->branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => 'completed',
        'pickup_at' => '2026-02-28 00:00:00',
        'expected_return_at' => '2026-03-05 00:00:00',
        'actual_pickup_at' => '2026-02-28 00:00:00',
        'actual_return_at' => '2026-03-05 00:00:00',
        'daily_rate' => 5000.00,
        'days_count' => 5,
        'subtotal' => 25000.00,
        'total_amount' => 25000.00,
        'created_by_id' => $this->user->id,
    ]);

    $row = collect($this->reportService->carProfitability(
        CarbonImmutable::parse('2026-03-01 00:00:00'),
        CarbonImmutable::parse('2026-03-10 23:59:59'),
        $this->branch->id,
    ))->firstWhere('car_id', $car->id);

    // Only 1 Mar 00:00 → 5 Mar 00:00 falls inside the window.
    expect($row['rental_days'])->toBe(4.0);
});

it('ages receivables by invoice date and drops those already settled', function () {
    $settled = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $owing = Customer::factory()->create(['branch_id' => $this->branch->id]);

    // Settled customer: invoiced 120 days ago, paid in full last week.
    ($this->post)([
        'debitAccountId' => $this->account1110->id,
        'creditAccountId' => $this->account4010->id,
        'amount' => '40000.00',
        'occurredOn' => new DateTimeImmutable('-120 days'),
        'customerId' => $settled->id,
    ]);
    ($this->post)([
        'debitAccountId' => $this->account1010->id,
        'creditAccountId' => $this->account1110->id,
        'amount' => '40000.00',
        'type' => TransactionType::Payment,
        'occurredOn' => new DateTimeImmutable('-7 days'),
        'customerId' => $settled->id,
    ]);

    // Owing customer: 100 days old, still unpaid.
    ($this->post)([
        'debitAccountId' => $this->account1110->id,
        'creditAccountId' => $this->account4010->id,
        'amount' => '15000.00',
        'occurredOn' => new DateTimeImmutable('-100 days'),
        'customerId' => $owing->id,
    ]);

    $ageing = $this->reportService->receivablesAgeing($this->branch->id);

    // The settled invoice must leave the ageing entirely; only the 15 000 remains,
    // aged from its own invoice date rather than today.
    expect($ageing['90_plus'])->toBe(15000.0)
        ->and($ageing['0_30'])->toBe(0.0)
        ->and($ageing['31_60'])->toBe(0.0)
        ->and($ageing['61_90'])->toBe(0.0);
});

it('applies a partial payment against the oldest invoice first', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    foreach ([['-100 days', '10000.00'], ['-10 days', '25000.00']] as [$when, $amount]) {
        ($this->post)([
            'debitAccountId' => $this->account1110->id,
            'creditAccountId' => $this->account4010->id,
            'amount' => $amount,
            'occurredOn' => new DateTimeImmutable($when),
            'customerId' => $customer->id,
        ]);
    }

    // A 10 000 payment clears the oldest invoice exactly.
    ($this->post)([
        'debitAccountId' => $this->account1010->id,
        'creditAccountId' => $this->account1110->id,
        'amount' => '10000.00',
        'type' => TransactionType::Payment,
        'occurredOn' => new DateTimeImmutable('-1 day'),
        'customerId' => $customer->id,
    ]);

    $ageing = $this->reportService->receivablesAgeing($this->branch->id);

    expect($ageing['90_plus'])->toBe(0.0)
        ->and($ageing['0_30'])->toBe(25000.0);
});

it('invalidates cached reports when a new transaction is posted', function () {
    $today = CarbonImmutable::today();

    ($this->post)(['amount' => '10000.00']);
    expect($this->reportService->dailyKpis($this->branch->id)['daily_revenue'])->toBe(10000.0);

    // Second posting must not be served from the primed cache.
    ($this->post)(['amount' => '5000.00']);

    expect($this->reportService->dailyKpis($this->branch->id)['daily_revenue'])->toBe(15000.0);
});

it('does not serve one branch cached figures from another', function () {
    $other = Branch::factory()->create(['code' => 'SOUTH']);

    ($this->post)(['amount' => '30000.00']);
    ($this->post)(['amount' => '7000.00', 'branchId' => $other->id]);

    // Prime the first branch, then read the second.
    expect($this->reportService->dailyKpis($this->branch->id)['daily_revenue'])->toBe(30000.0)
        ->and($this->reportService->dailyKpis($other->id)['daily_revenue'])->toBe(7000.0)
        ->and($this->reportService->dailyKpis(null)['daily_revenue'])->toBe(37000.0);

    // And re-reading the primed branch still returns its own figure.
    expect($this->reportService->dailyKpis($this->branch->id)['daily_revenue'])->toBe(30000.0);
});

it('hides every financial widget from a receptionist but keeps the worklist', function () {
    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole('receptionist');

    $this->actingAs($receptionist);

    $financial = [
        MonthlyRevenueExpenseChart::class,
        NetProfitTrendChart::class,
        TopCarsByProfitTable::class,
        TopCustomersTable::class,
        ReceivablesAgeingWidget::class,
    ];

    foreach ($financial as $widget) {
        expect($widget::canView())->toBeFalse("{$widget} must be hidden from a receptionist");
    }

    // The day's operational worklist stays visible.
    expect(DueReturnsTodayTable::canView())->toBeTrue()
        ->and(DailyOverviewStats::canView())->toBeTrue()
        ->and(FleetOccupancyGauge::canView())->toBeTrue();

    $this->actingAs($this->user); // manager

    foreach ($financial as $widget) {
        expect($widget::canView())->toBeTrue("{$widget} must be visible to a manager");
    }
});

it('omits profit stats from the daily overview for a receptionist', function () {
    ($this->post)(['amount' => '50000.00']);

    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole('receptionist');

    $this->actingAs($receptionist);
    $labels = statLabels(new DailyOverviewStats);

    expect($labels)->not->toContain("Today's Revenue")
        ->and($labels)->not->toContain("Today's Net Profit")
        ->and($labels)->toContain('Cash on Hand');

    $this->actingAs($this->user); // manager
    $managerLabels = statLabels(new DailyOverviewStats);

    expect($managerLabels)->toContain("Today's Revenue")
        ->and($managerLabels)->toContain("Today's Net Profit");
});

it('renders every dashboard widget without erroring', function () {
    $car = Car::factory()->create(['branch_id' => $this->branch->id]);
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    ($this->post)(['amount' => '40000.00', 'carId' => $car->id, 'customerId' => $customer->id]);
    ($this->post)([
        'debitAccountId' => $this->account5010->id,
        'creditAccountId' => $this->account1010->id,
        'amount' => '6000.00',
        'type' => TransactionType::Fuel,
        'carId' => $car->id,
    ]);

    $this->actingAs($this->user); // manager — sees everything
    Filament::setCurrentPanel('admin');

    $widgets = [
        DailyOverviewStats::class,
        DueReturnsTodayTable::class,
        UpcomingPickupsTable::class,
        OverdueReturnsTable::class,
        MonthlyRevenueExpenseChart::class,
        NetProfitTrendChart::class,
        CashFlowChart::class,
        ExpenseBreakdownChart::class,
        FleetOccupancyGauge::class,
        TopCarsByProfitTable::class,
        TopCustomersTable::class,
        ReceivablesAgeingWidget::class,
    ];

    foreach ($widgets as $widget) {
        Livewire::test($widget)->assertOk();
    }
});

it('serves the dashboard itself to a manager', function () {
    $this->actingAs($this->user)
        ->get('/admin')
        ->assertSuccessful();
});

it('pins a branch-restricted user to their own branch regardless of the filter', function () {
    $other = Branch::factory()->create(['code' => 'NORTH']);

    ($this->post)(['amount' => '30000.00']);                        // own branch
    ($this->post)(['amount' => '9000.00', 'branchId' => $other->id]); // someone else's

    $cashOnHand = fn (DailyOverviewStats $widget): string => widgetStats($widget)
        ->first(fn ($stat) => $stat->getLabel() === 'Cash on Hand')
        ->getValue();

    // A receptionist is tied to MAIN. The filter value arrives from the browser, so
    // asking for another branch must change nothing.
    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole('receptionist');
    $this->actingAs($receptionist);

    $widget = new DailyOverviewStats;
    $widget->pageFilters = ['branch_id' => $other->id];

    expect($cashOnHand($widget))->toBe('30,000.00 DZD');

    // A manager holds branches.view_all, so for them the filter is honoured.
    $this->actingAs($this->user);

    $managerWidget = new DailyOverviewStats;
    $managerWidget->pageFilters = ['branch_id' => $other->id];

    expect($cashOnHand($managerWidget))->toBe('9,000.00 DZD');
});
