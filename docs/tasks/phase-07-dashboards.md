# Phase 7 — Dashboards, KPIs & Charts

**Status: ✅ Done** · Depends on: Phases 4, 5, 6 · Closes: **REQ-01**, **REQ-10** (UI), **REQ-11**, **REQ-18**

The manager opens one screen and knows how the business is doing.

## Read first
[`../05-accounting-model.md`](../05-accounting-model.md) §Derivation queries — the reference SQL ·
[`../02-filament-panels.md`](../02-filament-panels.md) §Widgets ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-014

## Deliverables

### `ReportService` — the single home for every ledger aggregation
- [x] `dailyKpis()`, `monthlyKpis()`, `profitAndLoss()`, `carProfitability()`, `fleetProfitability()`,
      `customerStatement()`, `cashFlow()`, `occupancyRate()`, `expenseBreakdown()`,
      `receivablesAgeing()`
- [x] Plus `singleCarProfitability()` (car page, avoids scanning the whole fleet for one row) and
      `topCustomers()`

Every widget and every export calls this, so **"profit" has exactly one definition in the system**.
The credits−debits subtraction is applied in *every* revenue/expense aggregation via one shared
`revenueExpenseSelect()`, so a refund cannot reduce revenue on one screen and inflate expenses on
another. Covered by `it reduces revenue when a posting is reversed rather than inflating expenses`.

### Daily widgets (REQ-01)
- [x] Available / rented / maintenance counts
- [x] **Due-returns table** — the day's operational worklist
- [x] Upcoming pickups · overdue returns
- [x] Daily revenue · daily expenses · daily net profit (with 7-day sparkline)
- [x] Cash on hand, from `CashRegisterService`

### Monthly widgets (REQ-18)
- [x] Revenue-vs-expense chart (12 months) · net profit trend
- [x] **Cash flow — excluding internal transfers**
- [x] Fleet occupancy gauge · top cars by profit · top customers
- [x] Expense breakdown donut · receivables ageing (0-30/31-60/61-90/90+)

### Un-stub
- [x] **Car page Profitability tab** (REQ-11): revenue, expenses, net profit, rental days,
      utilisation %
- [x] Customer page Financials tab: invoiced, paid, owed, deposits held, fines

### Cross-cutting
- [x] Role-based `canView()` per widget, driven by a new `reports.view_financials` permission
      (super_admin, manager, accountant) rather than hardcoded role lists
- [x] Branch and date-range filters, on a `Dashboard` page using `HasFiltersForm`
- [x] Caching with a `TransactionPosted`-triggered flush, keyed per branch scope
- [ ] `ledger_daily_balances` — **not added.** The whole suite runs in ~17 s with every aggregation
      done live, and each report is now a single grouped query. Per ADR-014, no measurement justified
      the cache. Revisit when a real dataset makes a report slow.

## Decided here — `utilisation_pct` and occupancy

**The denominator is calendar days in the period. It does not subtract maintenance-blocked days.**

A car in the workshop for half the month therefore cannot exceed ~50% utilisation. That is the point:
downtime is lost earning capacity and the KPI exists to show it. The availability-adjusted alternative
reports a car that was off the road for two weeks as 100% utilised, hiding exactly the problem the
manager needs to see.

This one definition is used by `occupancyRate()`, `carProfitability()`, `singleCarProfitability()` and
`fleetProfitability()` — so the fleet gauge, the car page and the fleet report cannot disagree. It
**supersedes** the availability-adjusted formula sketched in
[`../05-accounting-model.md`](../05-accounting-model.md) §Fleet occupancy.

`fleetProfitability()` reports total rented days ÷ total capacity days, *not* the mean of per-car
percentages — averaging percentages would weight a car acquired yesterday the same as one present all
period.

## Tests

`tests/Feature/Phase7Test.php` — 18 tests.

- [x] Each KPI matches a hand-computed value on a seeded fixture
- [x] Per-car profit equals revenue − expenses for that car
- [x] Occupancy matches a manually-calculated fixture (4 rented car-days ÷ 2 cars × 10 days = 20%),
      plus a boundary case clipping a booking that overruns the period
- [x] Cash flow excludes cash-to-cash transfers
- [x] Cache invalidates on a new posting
- [x] Cache primed as one branch returns different figures for another
- [x] A receptionist cannot see profit widgets — asserted per widget, *and* that the operational
      worklist stays visible, *and* that a browser-supplied `branch_id` filter cannot widen a
      branch-restricted user's scope
- [x] Receivables ageing drops settled invoices and applies partial payments FIFO
- [x] All 12 widgets render through Livewire without erroring, and `/admin` serves the dashboard
      (verified the render test actually fails on a deliberately broken widget — Filament widgets are
      lazy, so an `assertOk()` that never executes `table()`/`getData()` would prove nothing)

## Bugs found and fixed while building this

| What | Where | Consequence if shipped |
|---|---|---|
| Report cache never invalidated | `FlushReportCache` forgot `reports:daily_kpis:branch_1`, but keys are written as `…:branch_1:2026-07-28` | Dashboards frozen for the 10-minute TTL after every posting |
| `receivablesAgeing()` summed AR debits only | `ReportService` | Every invoice ever raised stayed in a bucket forever; a fully-paid customer showed as owing |
| `AccountingService` resolved a non-existent class | `app(App\Services\AccountingService::class)` | Cash-on-hand stat threw on the live dashboard |
| Reference collision across branches | `AccountingService` scoped the sequence per branch but omitted the branch code | Branch B's *first ever* posting hit the global unique index on `transactions.reference` and failed |
| `TransactionPosted` fired pre-commit | `AccountingService::postMany()` | A concurrent reader could re-prime the cache from uncommitted state; a rollback flushed caches for rows that never existed |
| Admin panel had no dashboard page | `AdminPanelProvider` never registered one | All 12 widgets were discovered but nothing rendered them |

## Known issue left alone (Phase 4 scope)

`AccountingService::balanceOf()` ends with `Money::of(max(0, $balance))`, clamping a negative balance
to zero. An overdrawn till or a contra-asset account silently reads 0 rather than its real balance.
`CashRegisterService::cashOnHand()` (added here) sums in SQL and does not clamp, so the dashboard
figure is correct — but `balanceOf()` itself should be revisited.

## Definition of done

A dashboard populated by the data created in Phases 5–6, with every figure traceable back to
individual ledger rows. **Gates green:** Pest 163 passed · Pint 346 files clean · PHPStan 0 errors in
Phase 7 files (project total 455, down from 467 — all remaining are pre-existing, mostly
`missingType.generics` on factories; the project has no PHPStan baseline and has never been at zero).
