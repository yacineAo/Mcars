# Phase 7 — Dashboards, KPIs & Charts

**Status: ⬜** · Depends on: Phases 4, 5, 6 · Closes: **REQ-01**, **REQ-10** (UI), **REQ-11**, **REQ-18**

The manager opens one screen and knows how the business is doing.

## Read first
[`../05-accounting-model.md`](../05-accounting-model.md) §Derivation queries — the reference SQL ·
[`../02-filament-panels.md`](../02-filament-panels.md) §Widgets ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-014

## Deliverables

### `ReportService` — the single home for every ledger aggregation
- [ ] `dailyKpis()`, `monthlyKpis()`, `profitAndLoss()`, `carProfitability()`, `fleetProfitability()`,
      `customerStatement()`, `cashFlow()`, `occupancyRate()`, `expenseBreakdown()`,
      `receivablesAgeing()`

Every widget and every export calls this, so **"profit" has exactly one definition in the system**.
Implement the SQL from `../05-accounting-model.md` — including the subtraction of the opposite side
(`credits to revenue − debits to revenue`), which is what makes refunds and reversals reduce revenue
instead of inflating expenses.

### Daily widgets (REQ-01)
- [ ] Available / rented / maintenance counts
- [ ] **Due-returns table** — the day's operational worklist
- [ ] Upcoming pickups · overdue returns
- [ ] Daily revenue · daily expenses · daily net profit (with 7-day sparkline)
- [ ] Cash on hand, from `CashRegisterService`

### Monthly widgets (REQ-18)
- [ ] Revenue-vs-expense chart (12 months) · net profit trend
- [ ] **Cash flow — excluding internal transfers**, or banking the till shows as both an inflow and an
      outflow and doubles apparent turnover
- [ ] Fleet occupancy gauge · top cars by profit · top customers
- [ ] Expense breakdown donut · receivables ageing (0-30/31-60/61-90/90+)

### Un-stub
- [ ] **Car page Profitability tab** (REQ-11): revenue, expenses, net profit, rental days,
      utilisation %
- [ ] Customer page Financials tab: invoiced, paid, owed, deposits held, fines

### Cross-cutting
- [ ] Role-based `canView()` per widget — a receptionist sees returns and cash on hand, **not** profit
- [ ] Branch and date-range filters
- [ ] Caching with a `TransactionPosted`-triggered flush. **Cache keys must include the branch
      context** (or `global`), or Branch A's figures get served to Branch B — a confidentiality
      failure, not just wrong numbers.
- [ ] Add `ledger_daily_balances` **only if measurement proves it necessary**, and only as a
      rebuildable cache (ADR-014)

## Decide once, here

`utilisation_pct` — does the denominator subtract days the car was blocked for maintenance? Pick one
definition in `ReportService` and use it in the widget, the car page and the fleet report. Three
screens disagreeing about utilisation is the classic outcome of deciding this three times.

## Tests

- [ ] Each KPI matches a hand-computed value on a seeded fixture
- [ ] Per-car profit equals revenue − expenses for that car
- [ ] Occupancy matches a manually-calculated fixture
- [ ] Cash flow excludes cash-to-cash transfers
- [ ] Cache invalidates on a new posting
- [ ] Cache primed as one branch returns different figures for another
- [ ] A receptionist cannot see profit widgets

## Definition of done

A dashboard populated by the data created in Phases 5–6, with every figure traceable back to
individual ledger rows. Gates green.
