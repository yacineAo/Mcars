<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The single home for every ledger aggregation.
 *
 * Every widget, every page and every export calls this, so "profit" has exactly one
 * definition in the system. Reference SQL lives in docs/05-accounting-model.md
 * §Derivation queries.
 *
 * ## utilisation_pct / occupancy — the one definition (Phase 7 decision)
 *
 * The denominator is **calendar days in the period**. It does NOT subtract days a car
 * was blocked for maintenance or out of service.
 *
 * A car in the workshop for half the month therefore cannot exceed ~50% utilisation.
 * That is deliberate: downtime is lost earning capacity and the KPI is meant to show
 * it. The availability-adjusted alternative (dividing by rentable days only) reports a
 * car that was off the road for two weeks as 100% utilised, which hides exactly the
 * problem the manager needs to see.
 *
 * This supersedes the availability-adjusted formula sketched in
 * docs/05-accounting-model.md §Fleet occupancy. Used identically by the fleet gauge,
 * the car page and the fleet report — see docs/tasks/phase-07-dashboards.md.
 */
class ReportService
{
    /** Statuses that represent a car actually being out on rent. */
    private const OCCUPYING_STATUSES = [
        BookingStatus::Active->value,
        BookingStatus::Completed->value,
        BookingStatus::Overdue->value,
    ];

    /** Receivable control accounts: customers + fines. */
    private const RECEIVABLE_CODES = ['1110', '1120'];

    private const CACHE_TTL = 600;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CashRegisterService $cashRegisterService,
    ) {}

    /**
     * Daily KPIs (REQ-01)
     *
     * @return array<string, mixed>
     */
    public function dailyKpis(?int $branchId = null, ?DateTimeInterface $date = null): array
    {
        $targetDate = $date ? CarbonImmutable::parse($date) : CarbonImmutable::today();

        return $this->remember('daily_kpis', $branchId, [$targetDate->toDateString()], function () use ($branchId, $targetDate) {
            $carQuery = Car::query()->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));

            $countsByStatus = (clone $carQuery)
                ->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $bookings = fn () => Booking::query()
                ->when($branchId !== null, fn ($q) => $q->where('pickup_branch_id', $branchId));

            $dueReturnsCount = $bookings()
                ->whereIn('status', [BookingStatus::Active->value, BookingStatus::Overdue->value])
                ->whereDate('expected_return_at', $targetDate->toDateString())
                ->count();

            $upcomingPickupsCount = $bookings()
                ->where('status', BookingStatus::Confirmed->value)
                ->whereBetween('pickup_at', [$targetDate->startOfDay(), $targetDate->addDay()->endOfDay()])
                ->count();

            $overdueReturnsCount = $bookings()
                ->where('status', BookingStatus::Active->value)
                ->where('expected_return_at', '<', CarbonImmutable::now())
                ->count();

            $pnl = $this->profitAndLoss($targetDate->startOfDay(), $targetDate->endOfDay(), $branchId);

            return [
                'available_cars' => (int) ($countsByStatus[CarStatus::Available->value] ?? 0),
                'rented_cars' => (int) ($countsByStatus[CarStatus::Rented->value] ?? 0),
                'maintenance_cars' => (int) ($countsByStatus[CarStatus::Maintenance->value] ?? 0),
                'due_returns_count' => $dueReturnsCount,
                'upcoming_pickups_count' => $upcomingPickupsCount,
                'overdue_returns_count' => $overdueReturnsCount,
                'daily_revenue' => $pnl['revenue'],
                'daily_expenses' => $pnl['expenses'],
                'daily_net_profit' => $pnl['net_profit'],
                'revenue_sparkline' => $this->revenueSparkline($targetDate, $branchId),
                'cash_on_hand' => $this->cashOnHand($branchId),
            ];
        });
    }

    /**
     * Monthly KPIs (REQ-18)
     *
     * @return array<string, mixed>
     */
    public function monthlyKpis(?int $branchId = null, ?int $year = null, ?int $month = null): array
    {
        $today = CarbonImmutable::today();
        $year ??= $today->year;
        $month ??= $today->month;

        return $this->remember('monthly_kpis', $branchId, [$year, $month], function () use ($branchId, $year, $month) {
            $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $to = $from->endOfMonth();

            $pnl = $this->profitAndLoss($from, $to, $branchId);

            return [
                'monthly_revenue' => $pnl['revenue'],
                'monthly_expenses' => $pnl['expenses'],
                'monthly_net_profit' => $pnl['net_profit'],
                'occupancy_rate' => $this->occupancyRate($from, $to, $branchId),
            ];
        });
    }

    /**
     * Profit & loss (REQ-10).
     *
     * Subtracting the opposite side is what makes reversals and refunds reduce revenue
     * instead of inflating expenses.
     *
     * @return array{revenue: float, expenses: float, net_profit: float}
     */
    public function profitAndLoss(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $result = $this->ledger($from, $to, $branchId)
            ->selectRaw($this->revenueExpenseSelect())
            ->first();

        return $this->asPnl((float) ($result->revenue ?? 0), (float) ($result->expenses ?? 0));
    }

    /**
     * Per-car profitability (REQ-02, REQ-11).
     *
     * One grouped ledger query plus one grouped usage query for the whole fleet —
     * never a query per car.
     *
     * @return list<array<string, mixed>>
     */
    public function carProfitability(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $periodDays = $this->periodDays($from, $to);

        $ledger = $this->ledger($from, $to, $branchId)
            ->whereNotNull('transactions.car_id')
            ->groupBy('transactions.car_id')
            ->selectRaw('transactions.car_id, '.$this->revenueExpenseSelect())
            ->get()
            ->keyBy('car_id');

        $rentalDays = $this->rentalDaysByCar($from, $to, $branchId);

        $cars = Car::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'registration_number', 'brand', 'model']);

        $rows = $cars->map(function (Car $car) use ($ledger, $rentalDays, $periodDays): array {
            $revenue = (float) ($ledger[$car->id]->revenue ?? 0);
            $expenses = (float) ($ledger[$car->id]->expenses ?? 0);
            $days = (float) ($rentalDays[$car->id] ?? 0);

            return [
                'car_id' => $car->id,
                'registration_number' => $car->registration_number,
                'brand' => $car->brand,
                'model' => $car->model,
                ...$this->asPnl($revenue, $expenses),
                'rental_days' => round($days, 1),
                'utilisation_pct' => $this->asPercentage($days, $periodDays),
            ];
        })->all();

        usort($rows, fn (array $a, array $b) => $b['net_profit'] <=> $a['net_profit']);

        return $rows;
    }

    /**
     * Profitability for a single car — for the car page's Profitability tab (REQ-11).
     *
     * @return array<string, mixed>|null
     */
    public function singleCarProfitability(int $carId, DateTimeInterface $from, DateTimeInterface $to): ?array
    {
        $car = Car::query()->find($carId);

        if ($car === null) {
            return null;
        }

        $ledger = $this->ledger($from, $to)
            ->where('transactions.car_id', $carId)
            ->selectRaw($this->revenueExpenseSelect())
            ->first();

        $days = (float) ($this->rentalDaysByCar($from, $to, null, $carId)[$carId] ?? 0);

        return [
            'car_id' => $carId,
            'registration_number' => $car->registration_number,
            'brand' => $car->brand,
            'model' => $car->model,
            ...$this->asPnl((float) ($ledger->revenue ?? 0), (float) ($ledger->expenses ?? 0)),
            'rental_days' => round($days, 1),
            'utilisation_pct' => $this->asPercentage($days, $this->periodDays($from, $to)),
        ];
    }

    /**
     * Fleet-wide roll-up of per-car profitability.
     *
     * @return array<string, mixed>
     */
    public function fleetProfitability(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $cars = $this->carProfitability($from, $to, $branchId);

        $totalRevenue = array_sum(array_column($cars, 'revenue'));
        $totalExpenses = array_sum(array_column($cars, 'expenses'));

        // Fleet utilisation is total rented days over total capacity days, not the mean
        // of per-car percentages — averaging percentages weights a car that joined the
        // fleet yesterday the same as one present all period.
        $totalRentalDays = array_sum(array_column($cars, 'rental_days'));
        $capacityDays = count($cars) * $this->periodDays($from, $to);

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_expenses' => round($totalExpenses, 2),
            'total_net_profit' => round($totalRevenue - $totalExpenses, 2),
            'avg_utilisation_pct' => $this->asPercentage($totalRentalDays, $capacityDays),
            'top_car' => $cars[0] ?? null,
            'cars' => $cars,
        ];
    }

    /**
     * Customer financial statement (REQ-04).
     *
     * `owed` is positive when the customer owes the company, negative for a credit
     * balance. Deposits sit in 2100 and are reported separately — never as revenue.
     *
     * @return array<string, mixed>
     */
    public function customerStatement(int $customerId): array
    {
        Customer::query()->findOrFail($customerId);

        $receivableIds = $this->receivableAccountIds();

        $ledger = $this->ledgerBase()
            ->where('transactions.customer_id', $customerId)
            ->selectRaw(
                $this->revenueExpenseSelect().',
                COALESCE(SUM(CASE WHEN transactions.debit_account_id IN ('.$receivableIds.') THEN transactions.amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN transactions.credit_account_id IN ('.$receivableIds.') THEN transactions.amount ELSE 0 END), 0) AS owed,
                COALESCE(SUM(CASE WHEN transactions.credit_account_id IN ('.$receivableIds.') THEN transactions.amount ELSE 0 END), 0) AS settled',
            )
            ->first();

        $depositsHeld = (float) Deposit::query()
            ->where('customer_id', $customerId)
            ->where('status', 'held')
            ->sum('amount');

        return [
            'customer_id' => $customerId,
            'invoiced' => round((float) ($ledger->revenue ?? 0), 2),
            'paid' => round((float) ($ledger->settled ?? 0), 2),
            'owed' => round((float) ($ledger->owed ?? 0), 2),
            'deposits_held' => round($depositsHeld, 2),
            'active_fines_count' => Fine::query()
                ->where('customer_id', $customerId)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    /**
     * Top customers by net revenue in the period (REQ-18).
     *
     * Ranked on revenue rather than booking count so one high-value hire outranks a
     * string of cheap ones — and so cancelled-and-refunded bookings do not promote a
     * customer who has spent nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function topCustomers(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null, int $limit = 5): array
    {
        $rows = $this->ledger($from, $to, $branchId)
            ->join('customers as c', 'c.id', '=', 'transactions.customer_id')
            ->whereNotNull('transactions.customer_id')
            ->groupBy('c.id', 'c.code', 'c.first_name', 'c.last_name', 'c.phone')
            ->havingRaw("COALESCE(SUM(CASE WHEN cr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0)
                       - COALESCE(SUM(CASE WHEN dr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0) > 0")
            ->orderByRaw('revenue DESC')
            ->limit($limit)
            ->selectRaw("c.id, c.code, c.first_name, c.last_name, c.phone,
                COALESCE(SUM(CASE WHEN cr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN dr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0) AS revenue")
            ->get();

        return $rows->map(fn ($row): array => [
            'customer_id' => (int) $row->id,
            'code' => $row->code,
            'name' => trim($row->first_name.' '.$row->last_name),
            'phone' => $row->phone,
            'revenue' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * Cash flow (REQ-18) — excluding internal transfers.
     *
     * Banking the till moves cash between two cash-equivalent accounts. Counting it
     * would show as both an inflow and an outflow and double apparent turnover.
     *
     * @return array{cash_in: float, cash_out: float, net_cash_flow: float}
     */
    public function cashFlow(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $result = $this->ledger($from, $to, $branchId)
            ->where(fn ($q) => $q->where('dr.is_cash_equivalent', true)->orWhere('cr.is_cash_equivalent', true))
            ->whereRaw('NOT (dr.is_cash_equivalent AND cr.is_cash_equivalent)')
            ->selectRaw('
                COALESCE(SUM(CASE WHEN dr.is_cash_equivalent THEN transactions.amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN cr.is_cash_equivalent THEN transactions.amount ELSE 0 END), 0) AS cash_out
            ')
            ->first();

        $cashIn = round((float) ($result->cash_in ?? 0), 2);
        $cashOut = round((float) ($result->cash_out ?? 0), 2);

        return [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'net_cash_flow' => round($cashIn - $cashOut, 2),
        ];
    }

    /**
     * Fleet occupancy — rented car-days over calendar car-days.
     *
     * See the class docblock for why the denominator is not availability-adjusted.
     */
    public function occupancyRate(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): float
    {
        $totalCars = Car::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        if ($totalCars === 0) {
            return 0.0;
        }

        $rentedDays = array_sum($this->rentalDaysByCar($from, $to, $branchId));

        return $this->asPercentage($rentedDays, $totalCars * $this->periodDays($from, $to));
    }

    /**
     * Expense breakdown by category (REQ-18).
     *
     * Credits back to an expense account (refunds, reversals) reduce the category
     * rather than appearing elsewhere.
     *
     * @return array<string, float>
     */
    public function expenseBreakdown(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $rows = $this->ledger($from, $to, $branchId)
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'transactions.expense_category_id')
            ->where(fn ($q) => $q->where('dr.type', 'expense')->orWhere('cr.type', 'expense'))
            ->groupBy('category_name')
            ->selectRaw("
                COALESCE(ec.name, CASE WHEN dr.type = 'expense' THEN dr.name ELSE cr.name END) AS category_name,
                COALESCE(SUM(CASE WHEN dr.type = 'expense' THEN transactions.amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN cr.type = 'expense' THEN transactions.amount ELSE 0 END), 0) AS total_amount
            ")
            ->get();

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[(string) $row->category_name] = round((float) $row->total_amount, 2);
        }

        arsort($breakdown);

        return $breakdown;
    }

    /**
     * Receivables ageing (0-30 / 31-60 / 61-90 / 90+).
     *
     * Payments are applied FIFO against that customer's oldest open invoice, so a
     * settled invoice leaves the ageing entirely. Summing debits alone would leave
     * every invoice ever raised sitting in a bucket forever.
     *
     * @return array{'0_30': float, '31_60': float, '61_90': float, '90_plus': float}
     */
    public function receivablesAgeing(?int $branchId = null): array
    {
        return $this->remember('receivables_ageing', $branchId, [], function () use ($branchId) {
            $receivableIds = $this->receivableAccountIds();

            $rows = $this->db->table('transactions')
                ->whereRaw("(debit_account_id IN ({$receivableIds}) OR credit_account_id IN ({$receivableIds}))")
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('occurred_on')
                ->orderBy('id')
                ->get(['id', 'customer_id', 'occurred_on', 'amount', 'debit_account_id', 'credit_account_id']);

            $ids = explode(',', $receivableIds);
            $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];
            $today = CarbonImmutable::today();

            foreach ($rows->groupBy('customer_id') as $customerRows) {
                $open = [];
                $credit = 0.0;

                foreach ($customerRows as $row) {
                    $amount = (float) $row->amount;

                    if (in_array((string) $row->debit_account_id, $ids, true)) {
                        $open[] = ['date' => $row->occurred_on, 'amount' => $amount];
                    } elseif (in_array((string) $row->credit_account_id, $ids, true)) {
                        $credit += $amount;
                    }
                }

                // Apply payments FIFO against the oldest open invoices.
                foreach ($open as &$invoice) {
                    if ($credit <= 0.0) {
                        break;
                    }
                    $applied = min($credit, $invoice['amount']);
                    $invoice['amount'] -= $applied;
                    $credit -= $applied;
                }
                unset($invoice);

                foreach ($open as $invoice) {
                    if ($invoice['amount'] <= 0.0) {
                        continue;
                    }

                    $ageDays = CarbonImmutable::parse($invoice['date'])->startOfDay()->diffInDays($today);
                    $bucket = match (true) {
                        $ageDays <= 30 => '0_30',
                        $ageDays <= 60 => '31_60',
                        $ageDays <= 90 => '61_90',
                        default => '90_plus',
                    };

                    $buckets[$bucket] += $invoice['amount'];
                }
            }

            return array_map(fn (float $value) => round($value, 2), $buckets);
        });
    }

    /**
     * Owner statement — activity + balances for an owner over a period.
     *
     * @return array<string, mixed>
     */
    public function ownerStatement(int $carOwnerId, DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        $owner = CarOwner::query()->findOrFail($carOwnerId);

        $account2200 = ChartOfAccount::where('code', '2200')->value('id');

        $amountCredited = (float) $this->db->table('transactions')
            ->where('credit_account_id', $account2200)
            ->where('car_owner_id', $carOwnerId)
            ->whereBetween('occurred_on', $this->dateBounds($from, $to))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $amountDebited = (float) $this->db->table('transactions')
            ->where('debit_account_id', $account2200)
            ->where('car_owner_id', $carOwnerId)
            ->whereBetween('occurred_on', $this->dateBounds($from, $to))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $installments = OwnerInstallment::where('car_owner_id', $carOwnerId)
            ->whereBetween('due_date', $this->dateBounds($from, $to))
            ->get();

        $installmentData = [];
        $totalPaid = 0.0;

        foreach ($installments as $i) {
            $paid = $this->installmentPaidAmount($i->id);
            $totalPaid += $paid;

            $installmentData[] = [
                'period' => $i->period_month !== null && method_exists($i->period_month, 'format')
                    ? $i->period_month->format('Y-m')
                    : (string) $i->period_month,
                'due_date' => $i->due_date !== null && method_exists($i->due_date, 'format')
                    ? $i->due_date->format('Y-m-d')
                    : (string) $i->due_date,
                'amount_due' => (float) $i->amount_due,
                'amount_paid' => $paid,
                'status' => $i->status ? $i->status->value : (string) $i->status,
            ];
        }

        return [
            'owner_name' => trim($owner->first_name.' '.$owner->last_name),
            'installments' => $installmentData,
            'total_due' => round((float) $installments->sum('amount_due'), 2),
            'total_paid' => round($totalPaid, 2),
            'balance' => round($amountCredited - $amountDebited, 2),
        ];
    }

    /**
     * Cash session audit — all sessions with their expected vs counted amounts.
     *
     * @return list<array<string, mixed>>
     */
    public function cashSessionAudit(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): array
    {
        // opened_at is a timestamp, not a date — use full day bounds so sessions
        // opened after midnight on the last day are not dropped (dateBounds is
        // only correct for `date` columns).
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $query = $this->db->table('cash_sessions')
            ->leftJoin('users as opened', 'opened.id', '=', 'cash_sessions.opened_by_id')
            ->leftJoin('users as closed', 'closed.id', '=', 'cash_sessions.closed_by_id')
            ->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'cash_sessions.financial_account_id')
            ->whereBetween('cash_sessions.opened_at', [$from, $to])
            ->when($branchId !== null, fn ($q) => $q->where('cash_sessions.branch_id', $branchId));

        $rows = $query->selectRaw('
                cash_sessions.id,
                cash_sessions.opened_at,
                cash_sessions.closed_at,
                opened.name AS opened_by_name,
                closed.name AS closed_by_name,
                financial_accounts.name AS account_name,
                cash_sessions.opening_float,
                cash_sessions.counted_amount,
                cash_sessions.status,
                cash_sessions.notes
            ')
            ->orderBy('cash_sessions.opened_at', 'desc')
            ->get();

        $sessions = [];
        foreach ($rows as $row) {
            $expected = $this->expectedCashBalance((int) $row->id);
            $counted = $row->counted_amount === null ? null : Money::of((string) $row->counted_amount)->toDecimal();

            $sessions[] = [
                'id' => (int) $row->id,
                'opened_at' => $row->opened_at,
                'closed_at' => $row->closed_at,
                'opened_by' => $row->opened_by_name,
                'closed_by' => $row->closed_by_name,
                'account_name' => $row->account_name,
                'opening_float' => Money::of((string) $row->opening_float)->toDecimal(),
                'expected' => $expected,
                'counted' => $counted,
                // Nothing counted yet while the session is still open, so the
                // variance is null — the same convention the closing screen uses.
                'variance' => $counted === null ? null : Money::of($counted)->minus(Money::of($expected))->toDecimal(),
                'status' => $row->status,
                'notes' => $row->notes,
            ];
        }

        return $sessions;
    }

    /**
     * Expected cash balance for a session.
     *
     * The opening float is itself posted to the ledger as a transaction (E64,
     * carrying `cash_session_id`), so the net of the session's own movements
     * already includes it — adding `opening_float` again would double-count it.
     * The session's own close-time variance postings (E68/E69) are excluded so
     * the figure matches what the close computed. This deliberately mirrors
     * `CashRegisterService::calculateExpected()` so the audit report and the
     * screen that closes a session always agree.
     */
    private function expectedCashBalance(int $sessionId): string
    {
        $expected = $this->db->table('transactions')
            ->join('cash_sessions', 'cash_sessions.id', '=', 'transactions.cash_session_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'cash_sessions.financial_account_id')
            ->where('transactions.cash_session_id', $sessionId)
            ->whereNotIn('transactions.type', [TransactionType::CashOver->value, TransactionType::CashShort->value])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN transactions.debit_account_id = financial_accounts.ledger_account_id THEN transactions.amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN transactions.credit_account_id = financial_accounts.ledger_account_id THEN transactions.amount ELSE 0 END), 0) AS expected
            ')
            ->value('expected');

        return Money::of((string) $expected)->toDecimal();
    }

    /**
     * Paid amount for an installment, derived from the ledger.
     */
    private function installmentPaidAmount(int $installmentId): float
    {
        return (float) $this->db->table('transactions')
            ->where('source_type', 'owner_installment')
            ->where('source_id', $installmentId)
            ->where('debit_account_id', function ($q) {
                $q->select('id')->from('chart_of_accounts')->where('code', '2200');
            })
            ->sum('amount');
    }

    /**
     * Account 2600 — Inter-branch clearing. When computing company-wide totals,
     * add this filter to exclude inter-branch transfers.
     */
    public function interBranchClearingAccountId(): ?int
    {
        return ChartOfAccount::where('code', '2600')->value('id');
    }

    // ---------------------------------------------------------------- caching

    /**
     * Cache keys carry the branch scope and a per-scope version stamp.
     *
     * The version stamp is what makes invalidation work: keys are further qualified by
     * date, month, etc., so a listener cannot enumerate them to forget them. Bumping
     * the stamp orphans every key in that scope at once.
     *
     * Scoping by branch is a confidentiality control, not just a correctness one —
     * without it Branch A's figures get served to Branch B.
     */
    public static function cacheScope(?int $branchId): string
    {
        return $branchId !== null ? "branch_{$branchId}" : 'global';
    }

    public static function cacheVersion(?int $branchId): int
    {
        return (int) Cache::get('reports:version:'.self::cacheScope($branchId), 1);
    }

    /**
     * Invalidate every cached report for a branch scope (and the global roll-up).
     */
    public static function flushCache(?int $branchId = null): void
    {
        foreach (array_unique([self::cacheScope($branchId), 'global']) as $scope) {
            $key = "reports:version:{$scope}";
            Cache::forever($key, ((int) Cache::get($key, 1)) + 1);
        }
    }

    /**
     * @template TValue
     *
     * @param list<string|int> $parts
     * @param Closure(): TValue $callback
     * @return TValue
     */
    private function remember(string $name, ?int $branchId, array $parts, Closure $callback): mixed
    {
        $key = implode(':', [
            'reports',
            $name,
            self::cacheScope($branchId),
            'v'.self::cacheVersion($branchId),
            ...$parts,
        ]);

        return Cache::remember($key, self::CACHE_TTL, $callback);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The ledger joined to both sides of the chart of accounts, restricted to a period
     * and optionally a branch. Every P&L-shaped query starts here.
     *
     * Uses the query builder rather than the Transaction model: these queries return
     * aggregate rows, not transactions, and the ledger is append-only with no soft
     * deletes or scopes to respect.
     */
    private function ledger(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null): Builder
    {
        $query = $this->ledgerBase()
            ->whereBetween('transactions.occurred_on', $this->dateBounds($from, $to))
            ->when($branchId !== null, fn ($q) => $q->where('transactions.branch_id', $branchId));

        // Company-wide totals exclude inter-branch clearing (account 2600).
        // Per-branch queries include everything that happened in that branch.
        if ($branchId === null) {
            $clearingId = $this->interBranchClearingAccountId();
            if ($clearingId !== null) {
                $query->where('transactions.debit_account_id', '!=', $clearingId)
                    ->where('transactions.credit_account_id', '!=', $clearingId);
            }
        }

        return $query;
    }

    private function ledgerBase(): Builder
    {
        return $this->db->table('transactions')
            ->join('chart_of_accounts as dr', 'dr.id', '=', 'transactions.debit_account_id')
            ->join('chart_of_accounts as cr', 'cr.id', '=', 'transactions.credit_account_id');
    }

    /**
     * Rented days per car, from one grouped query.
     *
     * Overlap is clipped to the period so a booking spanning the boundary contributes
     * only the days that fall inside it.
     *
     * @return array<int, float> car_id => rental days
     */
    private function rentalDaysByCar(DateTimeInterface $from, DateTimeInterface $to, ?int $branchId = null, ?int $carId = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $rows = $this->db->table('bookings')
            ->whereIn('status', self::OCCUPYING_STATUSES)
            ->when($branchId !== null, fn ($q) => $q->where('pickup_branch_id', $branchId))
            ->when($carId !== null, fn ($q) => $q->where('car_id', $carId))
            ->whereRaw('COALESCE(actual_pickup_at, pickup_at) < ?', [$end])
            ->whereRaw('COALESCE(actual_return_at, expected_return_at) > ?', [$start])
            ->groupBy('car_id')
            ->selectRaw('car_id, SUM(GREATEST(0, EXTRACT(EPOCH FROM (
                    LEAST(COALESCE(actual_return_at, expected_return_at), ?)
                  - GREATEST(COALESCE(actual_pickup_at, pickup_at), ?)
                )) / 86400)) AS rental_days', [$end, $start])
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $days[(int) $row->car_id] = (float) $row->rental_days;
        }

        return $days;
    }

    /**
     * @return list<float>
     */
    private function revenueSparkline(CarbonImmutable $targetDate, ?int $branchId): array
    {
        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $targetDate->subDays($i);
            $sparkline[] = $this->profitAndLoss($day->startOfDay(), $day->endOfDay(), $branchId)['revenue'];
        }

        return $sparkline;
    }

    private function cashOnHand(?int $branchId): float
    {
        return (float) $this->cashRegisterService->cashOnHand($branchId)->toDecimal();
    }

    /**
     * The revenue/expense pair, both sides netted. Shared by every P&L-shaped query so
     * the definition cannot drift between them.
     */
    private function revenueExpenseSelect(): string
    {
        return "
            COALESCE(SUM(CASE WHEN cr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN dr.type = 'revenue' THEN transactions.amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN dr.type = 'expense' THEN transactions.amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN cr.type = 'expense' THEN transactions.amount ELSE 0 END), 0) AS expenses
        ";
    }

    /**
     * Comma-joined receivable account ids, safe to interpolate — sourced from the
     * chart of accounts and cast to int.
     */
    private function receivableAccountIds(): string
    {
        $ids = ChartOfAccount::query()
            ->whereIn('code', self::RECEIVABLE_CODES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids === [] ? '0' : implode(',', $ids);
    }

    /**
     * @return array{string, string}
     */
    private function dateBounds(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [
            CarbonImmutable::parse($from)->toDateString(),
            CarbonImmutable::parse($to)->toDateString(),
        ];
    }

    private function periodDays(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        return max(1, (int) $start->diffInDays($end) + 1);
    }

    /**
     * @return array{revenue: float, expenses: float, net_profit: float}
     */
    private function asPnl(float $revenue, float $expenses): array
    {
        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'net_profit' => round($revenue - $expenses, 2),
        ];
    }

    private function asPercentage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(min(100.0, ($numerator / $denominator) * 100), 1);
    }
}
