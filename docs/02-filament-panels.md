# 02 — Filament Panel Architecture

**One panel, one Laravel application, one `users` table.**

| Panel | Path | Audience | Roles |
|---|---|---|---|
| `admin` | `/admin` | Internal staff | `super_admin`, `manager`, `accountant`, `receptionist`, `maintenance_officer`, `supervisor` |

The system is **staff-only**. Customers and car owners are records the office manages, not accounts
that log in — see [ADR-007](06-design-decisions.md), which supersedes the original three-panel
design. Visibility inside the panel is governed by permissions, not by which panel you reached.


## The `admin` panel

Organised into navigation **groups** so a receptionist is not scrolling past forty items. (The design
called for Filament Clusters; the build uses `navigationGroup`, which achieves the same grouping.)

### Cluster: Fleet — REQ-02, REQ-03, REQ-12, REQ-13
| Resource | Notes |
|---|---|
| `CarResource` | The centrepiece. See "Car page" below. |
| `CarCategoryResource` | |
| `CarOwnerResource` | + relation managers: agreements, cars, instalments |
| `CarOwnershipAgreementResource` | |
| `CarDocumentResource` | Global list filterable by expiry — the renewals worklist |
| `MaintenanceLogResource` | Table + calendar view of scheduled work |
| `MaintenanceScheduleResource` | Service interval templates |
| `VendorResource` | Garages, insurers, parts suppliers |

**Car page (REQ-02)** — a `ViewRecord` page with tabs, not a flat form:
`Overview` (identity, status, photos, odometer) · `Documents` (expiry chips) · `Maintenance` (history +
next due) · `Bookings` (full contract history) · **`Profitability`** (revenue, expenses, net profit,
rental days, utilisation % — every figure a `ReportService` query over `transactions` where
`car_id = X`, REQ-11) · `Owner` (agreement + instalment status, third-party cars only) · `Activity`.

### Cluster: CRM — REQ-04
`CustomerResource` (relation managers: documents, bookings, contracts, fines, deposits, payments),
`CustomerDocumentResource`.
Customer view page shows **derived** amounts owed, deposits held, and fines — read from the ledger.

### Cluster: Bookings — REQ-05, REQ-06, ADV-01, ADV-02
| Resource / Page | Notes |
|---|---|
| ~~`BookingCalendarPage`~~ | **Not built.** Bookings are worked from the table view; `BookingAvailabilityService::calendarFeed()` exists for when it is. |
| `BookingResource` | Table view for search/filtering; wizard form for creation |
| `ContractResource` | Generate PDF, send, signature status, close-out |
| `ContractTemplateResource` | Per-locale templates (ar/fr/en) |
| `ConditionReportResource` | Usually reached from a booking, listed for audit |
| `ExtraResource` | Services catalogue |
| `CarBlockResource` | |

### Cluster: Finance — REQ-07, REQ-08, REQ-09, REQ-10, ADV-07
| Resource / Page | Notes |
|---|---|
| **`TransactionResource`** | **View-only.** No create, edit or delete action exists on this resource at all — not hidden, not permission-gated, *absent*. The ledger is written by `AccountingService` only. Filters: date range, account, type, car, customer, owner, branch. Row action: "Reverse" (creates a compensating entry with a mandatory reason). |
| ~~`CashRegisterPage`~~ | **Not built.** Shifts are opened and closed from `CashSessionResource`, which does post the variance. |
| `PaymentResource` | Records receipts and disbursements; posts via `AccountingService` |
| `PaymentScheduleResource` | Instalment plans, overdue filter |
| `ExpenseResource` | Draft → approval → paid workflow, recurring templates |
| `ExpenseCategoryResource` | Each mapped to a COA account |
| `DepositResource` | Hold / deduct / refund, with deduction line items |
| `OwnerInstallmentResource` | Generation, due, paid, overdue |
| `FinancialAccountResource` | Cash boxes, bank, CCP, BaridiMob, POS |
| `ChartOfAccountResource` | Accountant + super_admin only |
| `CashSessionResource` | Shift history and variance audit |

### Cluster: HR — REQ-15
`EmployeeResource`, `PayrollRunResource` (+ items relation manager), `EmployeeAdvanceResource`,
`CommissionResource`.

### Cluster: Operations
`FineResource` (with the liability-suggestion action), `NotificationLogResource` (delivery audit),
`AlertRuleResource`.

### Cluster: Reports — REQ-16
**Not built — Phase 9.** `ReportsHubPage` with per-report parameter forms and PDF/Excel export.
Figures are currently available only as dashboard widgets.

### Cluster: Settings & Access — REQ-20, ADV-03, ADV-06
Built: `UserResource`, `RoleResource` (Shield), `BranchResource`, `AlertRuleResource`,
`NotificationLogResource`.
Not built (Phase 10): `SettingsPage`, `ActivityLogResource`, `BackupsPage`.

### Role → visibility matrix (REQ-20)

| Cluster | manager | accountant | receptionist | maintenance | supervisor |
|---|:--:|:--:|:--:|:--:|:--:|
| Fleet | full | read | read | full (maintenance), read (rest) | read |
| CRM | full | read | full | — | read |
| Bookings | full | read | full | read (blocks) | full |
| Finance | full | full | payments + cash only | — | read |
| HR | full | payroll | — | — | read |
| Operations | full | fines (financial) | fines (assign) | — | full |
| Reports | full | full | — | — | read |
| Settings & Access | full | — | — | — | — |

Enforced by Filament Shield permissions, not by hiding navigation. Hidden navigation is not security.

---

## Retired: the `owner` and `client` panels

Both portals, and the four-layer data-isolation model that protected them, were removed when the
business confirmed the system is office-only.

What that deleted, and why none of it is missed:

| Was | Now |
|---|---|
| `owner` panel (REQ-19, ADV-08) | Owner statements are produced *by* staff in the admin panel. |
| `client` panel (ADV-09) | Customers are served by phone and at the counter. |
| Four isolation layers + permanent regression suite | No second audience exists to leak to, so there is nothing to isolate from. |
| `car_owner` / `client` roles | Removed from `UserRole`. |
| `EnsureUserIsCarOwner` / `EnsureUserIsClient` | Deleted. |

`car_owners.user_id` and `customers.user_id` are **kept but unused** — the seam if a portal is ever
wanted again. Reintroducing one means restoring the isolation model in full, not bolting a panel on:
the strongest layer was always that internal resources are simply never registered on a portal.

---


## Dashboard widgets (REQ-01, REQ-18)

All figures come from `ReportService`, which queries `transactions`. No widget maintains its own total.

### Daily KPIs — admin, top row
| Widget | Source |
|---|---|
| `AvailableCarsStat` / `RentedCarsStat` / `MaintenanceCarsStat` | `cars.status` counts |
| `DueReturnsTodayTable` | bookings where `expected_return_at::date = today` — the day's operational worklist |
| `UpcomingPickupsTable` | confirmed bookings starting today/tomorrow |
| `OverdueReturnsAlert` | `status = active` and `expected_return_at < now()` |
| `DailyRevenueStat` | sum of credits to revenue accounts, `occurred_on = today` |
| `DailyExpensesStat` | sum of debits to expense accounts, `occurred_on = today` |
| `DailyNetProfitStat` | revenue − expenses, with a 7-day sparkline |
| `CashOnHandStat` | `CashRegisterService::balance()` across cash-equivalent accounts |

### Monthly KPIs — admin
| Widget | Source |
|---|---|
| `MonthlyRevenueExpenseChart` | 12-month grouped bars |
| `NetProfitTrendChart` | line, 12 months |
| `CashFlowChart` | cash-equivalent in/out by month |
| `FleetOccupancyGauge` | rented car-days ÷ available car-days |
| `TopCarsByProfitTable` | ledger grouped by `car_id`, revenue − expenses (REQ-11) |
| `TopCustomersTable` | ledger grouped by `customer_id` |
| `ExpenseBreakdownChart` | donut by `expense_category_id` |
| `ReceivablesAgeingWidget` | AR balance bucketed 0-30/31-60/61-90/90+ |

### Alert widgets — REQ-17
`ExpiringDocumentsWidget` (insurance / inspection / registration / licences),
`UpcomingMaintenanceWidget`, `OwnerInstallmentsDueWidget`, `OverduePaymentsWidget`,
`RecurringExpensesDueWidget`, `OpenCashSessionsWidget`.

### Performance
Dashboard queries are cached (5–15 min TTL) and the cache tag is flushed by a `TransactionPosted`
event listener, so a payment taken at the desk updates the register immediately while the heavier
12-month charts ride the cache. If ledger scans become slow, add `ledger_daily_balances` — but only
after measuring, and only as a cache (see [`01-database-schema.md`](01-database-schema.md)).

### Widget visibility
Each widget declares `canView()` against a Shield permission. The receptionist sees operational
widgets (returns, pickups, cash on hand) but not profit or margin widgets.
