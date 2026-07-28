# 02 — Filament Panel Architecture

Three panels, one Laravel application, one `users` table.

| Panel | Path | Audience | Roles |
|---|---|---|---|
| `admin` | `/admin` | Internal staff | `super_admin`, `manager`, `accountant`, `receptionist`, `maintenance_officer`, `supervisor` |
| `owner` | `/owner` | External car owners / investors (REQ-19, ADV-08) | `car_owner` |
| `client` | `/client` | Renting customers (ADV-09) | `client` |

Each is a `PanelProvider` with its own middleware stack, brand colour, navigation and — critically —
its **own resource registry**. See [ADR-007](06-design-decisions.md) for why one `users` table and
role-based panel access rather than three auth guards.

---

## Panel 1 — `admin`

Organised with **Filament Clusters** so a receptionist is not scrolling past forty navigation items.

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
| **`BookingCalendarPage`** | Custom full-calendar page: resource-timeline, one row per car, drag to create, colour by status, maintenance blocks rendered inline. The primary daily work surface. |
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
| **`CashRegisterPage`** | Custom page: live balance per financial account, today's in/out with reason and operator (REQ-09), open/close shift, count and reconcile, transfer between accounts. |
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
`ReportsHubPage` — one page per report, each with a parameter form and PDF/Excel export buttons:
profit, expenses, customers, fleet, tax, cash flow, owner statements, receivables ageing.
Large exports queue and notify on completion.

### Cluster: Settings & Access — REQ-20, ADV-03, ADV-06
`UserResource`, `RoleResource` (Shield), `BranchResource`, `SettingsPage`,
`ActivityLogResource` (view-only), `BackupsPage`.

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

## Panel 2 — `owner` (REQ-19, ADV-08)

A car owner must see **their cars and their money — and nothing else about the business**. They must
never learn what the company charges customers, what other owners are paid, or what the fleet costs.

### Resources
| Resource | Access |
|---|---|
| `MyCarsResource` | Read-only. Whitelisted columns: brand, model, plate, status, current booking period *(dates only)*, insurance/inspection expiry. **No** daily rate, purchase price, customer names, or expense detail. |
| `MyInstallmentsResource` | Read-only: period, due date, amount due, status, amount paid, remaining. |
| `MyPaymentsResource` | Read-only receipts of money paid **to them**. |
| `MyStatementsPage` | Monthly statement per `OwnerStatementService`, downloadable as PDF. |
| `MyDocumentsResource` | Their own agreement + their cars' insurance/inspection documents. |
| `MyProfilePage` | Contact and bank/CCP details for payment. |

### Widgets
`MyFleetStatusOverview` (their cars by status) · `NextInstallmentDue` · `ReceivedThisYear` ·
`MonthlyReceiptsChart` · `MyCarsUtilisation` (rental days ÷ available days — activity, not margin).

### What "profit generated by their cars" means here
REQ-19 asks to show profit generated by the owner's cars. Under a `fixed_monthly` agreement, the
company's margin is confidential and showing it invites renegotiation of every contract. So the owner
panel shows **owner-scoped figures only**: gross rental days, gross rental revenue attributable to
their car, agreed rent, amounts paid, balance. Under a `revenue_share` agreement, gross revenue and the
share calculation are shown in full, because that *is* the owner's contractual entitlement.
`OwnerStatementService` picks the presentation from `car_ownership_agreements.model`.
**Flagged for review** — confirm this is the intended disclosure level before Phase 10.

---

## Panel 3 — `client` (ADV-09)

### Resources
| Resource | Access |
|---|---|
| `MyBookingsResource` | Read-only list + view; optional "request booking" form creating a `pending` booking that staff confirm. |
| `MyContractsResource` | View, download signed PDF, **sign pending contracts** (the e-signature landing point, REQ-06). |
| `MyInvoicesResource` | Amounts due, payment history, instalment schedule with due dates. |
| `MyFinesResource` | Fines assigned to them, with the notice scan. |
| `MyProfilePage` | Contact details; upload/renew ID and licence documents. |

### Widgets
`ActiveRentalCard` (car, return date/time, a prominent countdown) · `OutstandingBalance` ·
`DepositStatus` (held / refunded / deducted with reasons — ADV-07 transparency) ·
`NextPaymentDue` · `DocumentsExpiring`.

Financial resources, costs, other customers, and every Fleet/HR/Finance resource are **not registered
on this panel**.

---

## Data isolation — four layers, all required

Any one layer is a single point of failure. All four are specified because owner and client panels
expose the system to people outside the company.

### Layer 1 — Resource is not registered (strongest)
The owner panel does not register `ExpenseResource`, `TransactionResource`, `CustomerResource` or
anything else internal. **No route exists.** No policy bug, no forgotten scope, no permission
misconfiguration can expose a resource that was never routed. This is the primary control; the rest
are defence in depth.

### Layer 2 — Purpose-built read-only resources
Portal resources are separate classes (`App\Filament\Owner\Resources\MyCarsResource`), never the admin
resource re-registered with a filter. Columns are an explicit allowlist. A new column added to `cars`
in a later phase therefore cannot leak into the owner panel by default — it has to be added
deliberately. Reusing `CarResource` would leak `daily_rate` the moment someone adds it to the table.

### Layer 3 — Query scoping
Every portal resource overrides the base query:

```
MyInstallmentsResource::getEloquentQuery()
    → OwnerInstallment::query()->where('car_owner_id', auth()->user()->carOwner->id)
```

Backed by a `BelongsToOwner` / `BelongsToCustomer` trait plus a model **global scope**, so relation
managers, widget queries, exports and any `findOrFail()` inherit the same restriction. Scoping only the
resource table — and forgetting widgets — is the classic leak.

### Layer 4 — Policies
`OwnerInstallmentPolicy::view()` re-checks ownership independently of the query scope. This is what
stops IDOR: `/owner/installments/9999` must 403, not 404-by-luck.

### Cross-cutting
- **Files.** Contract PDFs and document scans live on a **private disk**, served through a controller
  that runs the policy and issues short-lived signed URLs. Never `Storage::url()` on a public disk —
  a leaked path would otherwise be permanently readable by anyone.
- **Panel middleware.** `EnsureUserIsCarOwner` / `EnsureUserIsClient` on the panel, plus
  `User::canAccessPanel(Panel $panel)` checking the role.
- **Global search** disabled on portal panels — it is a well-known way to enumerate records that the
  navigation hides.
- **Notifications** filtered by `notifiable`, so an owner cannot see an alert about another owner.
- **Rate limiting** on portal login, OTP verification, and PDF download endpoints.
- **Impersonation** (staff viewing a portal as a user) is `super_admin` only, logged to `activity_log`,
  and banner-visible.

### Regression tests (Phase 10 deliverable, permanent)
```
owner A cannot list owner B's installments
owner A cannot GET /owner/installments/{B's id}          → 403
owner A cannot download B's agreement PDF                 → 403
owner panel exposes no route matching /expenses|/transactions|/customers
client A cannot view client B's contract                  → 403
client cannot see any expense, cost or margin field in any JSON response
portal widget queries are scoped (assert generated SQL contains the owner/customer predicate)
```

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
