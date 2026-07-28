# 04 — Implementation Roadmap

Eleven phases. Each is a **self-contained work session**: it depends only on phases before it, ends
with a working, demonstrable increment, and closes a named set of `REQ-*` IDs from
[`00-functional-requirements.md`](00-functional-requirements.md).

## Two adjustments to the requested order

**1. A Phase 0 was added.** Scaffolding, Docker/Postgres, conventions and shared primitives (Money,
enums, sequences) are ~half a day but every later phase assumes them. Doing this inside Phase 1 makes
Phase 1 twice its intended size.

**2. The accounting ledger moved ahead of bookings and contracts** — the original Phase 5 becomes
Phase 4, and the original Phase 4 becomes Phase 5.

As originally ordered, Phase 4 builds bookings and contracts, which take security deposits and issue
invoices — money events that the ledger from Phase 5 does not yet exist to record. That leaves two
bad options: build a temporary money-tracking mechanism and rip it out a phase later, or ship a phase
whose central feature is knowingly broken.

The swap is clean because the dependency only runs one way: the ledger's `booking_id`, `contract_id`
and `car_id` are nullable dimensions, so `transactions` has no structural dependency on bookings at
all. Phase 4 can be built and tested end-to-end against expenses alone.

*Fallback if the original order must hold:* Phase 4 emits `BookingConfirmed` / `PaymentReceived` /
`DepositTaken` domain events and records **no** money; Phase 5 adds the listeners that post them, then
backfills via a replay command. This works, but it means Phase 4 cannot be demonstrated as complete
and the backfill is a real risk. The swap is strongly recommended.

---

## Phase 0 — Foundation

**Goal.** A running skeleton every later phase can build on without re-litigating decisions.

**Depends on.** Nothing.

**Deliverables**
- Laravel app scaffolded. **Verify the current stable Laravel and Filament v4 versions at install
  time** — Filament v4 requires Laravel ≥ 11.28 and PHP ≥ 8.2; do not assume, run `composer why-not`.
- `docker-compose.yml`: PHP-FPM, **PostgreSQL 16+**, Redis, Nginx, Mailpit, queue worker, scheduler.
- Extensions enabled: `btree_gist` (needed for the booking exclusion constraint), `pg_trgm`.
- Packages: `filament/filament` v4, `spatie/laravel-permission`, `bezhansalleh/filament-shield`,
  `spatie/laravel-medialibrary`, `spatie/laravel-activitylog`, `spatie/laravel-backup`,
  `spatie/laravel-settings`, a PDF renderer, `maatwebsite/excel`.
- Shared primitives: `Money` value object (integer-safe `decimal(18,2)` handling), `Period` DTO,
  `SequenceGenerator` (`sequences` table + row-locked allocation), base `Enum` trait with labels/colours
  for Filament, `HasBranch` and `HasAuditColumns` traits.
- Localisation: `ar` (RTL), `fr`, `en` language files; `Africa/Algiers`; DZD formatting.
- `CLAUDE.md` at the repo root pointing at `docs/`, plus the conventions from
  [`01-database-schema.md`](01-database-schema.md).
- Pest + PHPStan (level 6+) + Pint, wired into CI.

**Definition of done.** `docker compose up` serves the app; `php artisan test` is green; CI passes.

---

## Phase 1 — Auth, Roles & Permissions, Panel Skeletons

**Goal.** Three panels exist, users log into the correct one, permissions are enforced.
**Depends on.** Phase 0.
**Closes.** REQ-20 · ADV-06 *(schema only)*

**Deliverables**
- `branches` table + seeder with one default branch. **`branch_id` (nullable) added to every table
  from this phase onward.** Enforcement stays off until Phase 10 — see ADR-004; retrofitting it onto a
  populated, append-only ledger later is a genuinely painful migration.
- `users` table extended (phone, avatar, locale, `is_active`, 2FA, `last_login_at`).
- Spatie Permission + Filament Shield installed; roles seeded: `super_admin`, `manager`, `accountant`,
  `receptionist`, `maintenance_officer`, `supervisor`, `car_owner`, `client`.
- Three `PanelProvider`s: `admin`, `owner`, `client` — each with branding, its own middleware,
  and an empty navigation. `User::canAccessPanel()` gates by role.
- `EnsureUserIsCarOwner` / `EnsureUserIsClient` middleware.
- `UserResource`, `RoleResource`, `BranchResource` in the admin panel.
- Login, password reset, optional 2FA. Rate limiting on all auth endpoints.

**Tests.** A `client` user cannot reach `/admin` (403). A `receptionist` cannot reach `/owner`.
Each seeded role's permission set matches the matrix in [`02-filament-panels.md`](02-filament-panels.md).

**Demo.** Log in as each role; see the correct panel and nothing else.

---

## Phase 2 — Fleet Management ✅

**Goal.** The full fleet is in the system with documents, owners and maintenance.
**Depends on.** Phase 1.
**Closes.** REQ-02 *(except profitability tab)* · REQ-03 *(schema + agreements)* · REQ-12 · REQ-13 *(data)* · ADV-02 *(car documents)*

**Status: Complete** (commit `ed843cc`)

**Deliverables**
- Tables: `branches` *(done)*, `car_categories`, `car_owners`, `car_ownership_agreements`, `cars`,
  `car_documents`, `maintenance_logs`, `maintenance_schedules`, `vendors`.
- Enums: `CarStatus`, `OwnershipType`, `FuelType`, `TransmissionType`, `CarDocumentType`,
  `MaintenanceType`, `MaintenanceStatus`, `AgreementModel`, `AgreementStatus`, `BodyType`, `VendorType`.
- Resources: `CarResource` (view page with sections), `CarOwnerResource`,
  `CarOwnershipAgreementResource`, `CarDocumentResource`, `MaintenanceLogResource`,
  `MaintenanceScheduleResource`, `VendorResource`, `CarCategoryResource`.
- Media Library: car `gallery` and `damage` collections, document scans on **private disk**.
- `FleetStatusService` — status state machine with illegal-transition guards.
- `MaintenanceSchedulerService` — next-due computation from km/date intervals.
- `CarDocumentObserver` keeping the `cars.*_expiry_date` mirror columns in sync.
- `EXCLUDE` constraint preventing overlapping active ownership agreements per car.
- 6 factories, 2 seeders (car categories, vendors).
- Translations for all fleet enums in ar/fr/en.

**Explicitly deferred.** Maintenance costs are recorded on the log but **not posted to the ledger** —
that wiring lands in Phase 4.

**Tests.** 14 tests: illegal status transitions rejected, overlapping agreements rejected by DB,
document expiry mirror stays correct after edits, seeders create data.

---

## Phase 3 — CRM ✅

**Goal.** Customers and their identity documents are managed and verifiable.
**Depends on.** Phase 1.
**Closes.** REQ-04 *(except financial tabs)* · ADV-02 *(customer documents)*

**Status: Complete**

**Deliverables**
- Tables: `customers`, `customer_documents`.
- Enums: `CustomerType`, `CustomerDocumentType`, `CustomerSource`, `CustomerGender`.
- `CustomerResource` with a view page (Profile / Driving Licence / Contact / Commercial sections)
  and Documents relation manager.
- Document upload with front/back images on **private disk**, expiry tracking, verify action.
- Blacklist flag with conditional reason field.
- Database-level unique partial indexes on `national_id`, `driving_license_number`, `phone`.
- Rating field (1–5) with DB CHECK constraint.
- Auto-generated customer code (IND-xxx / COM-xxx).
- Translations for all CRM enums in ar/fr/en.

**Tests.** 9 tests: customer creation, duplicate detection on all three fields, blacklist,
rating validation, document verification.

---

## Phase 4 — Accounting Ledger & Cash Register *(moved ahead of bookings)*

**Status: Complete** (commit `3139c87`)

**Goal.** The financial backbone. Every later phase records money through it and nothing else.
**Depends on.** Phases 1–2.
**Closes.** REQ-08 · REQ-09 · REQ-10 *(engine; UI in Phase 7)*

> This is the highest-risk phase and the one to get right. Read
> [`05-accounting-model.md`](05-accounting-model.md) in full before starting.

**Deliverables**
- Tables: `chart_of_accounts`, **`transactions`**, `financial_accounts`, `expense_categories`,
  `expenses`, `cash_sessions`, `sequences` *(done in Phase 0)*.
- `cash_register_entries` **view** over `transactions`, plus its read-only Eloquent model.
- Seeders: the full chart of accounts and the expense categories from
  [`05-accounting-model.md`](05-accounting-model.md); default financial accounts per branch.
- **Immutability enforcement**: Postgres trigger blocking `UPDATE`/`DELETE` on `transactions`;
  model-level guards; a policy denying create/update/delete from every UI path.
- `AccountingService` (`post`, `postMany`, `reverse`, `balanceOf`) + `ExpensePoster`,
  `MaintenancePoster`, `CashSessionPoster`.
- `CashRegisterService` (balance, open/close session, count, variance posting, transfer).
- Resources: `TransactionResource` **(view-only, with a Reverse action)**, `ExpenseResource`
  (draft → approval → paid), `ExpenseCategoryResource`, `FinancialAccountResource`,
  `ChartOfAccountResource`, `CashSessionResource`.

**Deferred to later phases**
- `CashRegisterPage` dedicated live-balance page — session management works via `CashSessionResource`.
- Recurring expense generator (office rent, internet, electricity) as a scheduled job.
- Retro-wire Phase 2: completed maintenance logs and renewed car documents posting their expenses.
- Nightly integrity-check command.

**Tests.** 15 tests: posting, balance queries, reversals, immutability, expense lifecycle,
cash sessions (open/close/variance/balance), consecutive references. All pass.

**Demo.** Open a register with a float, record a fuel expense against a car, close the register with a
deliberate 500 DZD discrepancy, and show the variance posted and flagged.

---

## Phase 5 — Bookings, Calendar & Contracts *(was Phase 4)*

**Goal.** Rentals can be booked without collision and contracts generated, signed and delivered.
**Depends on.** Phases 2, 3, 4.
**Closes.** REQ-05 · REQ-06 · ADV-01 · ADV-02 *(contract archive)* · ADV-05 *(contract delivery)*

**Deliverables**
- Tables: `bookings`, `car_blocks`, `extras`, `booking_extras`, `condition_reports`,
  `contract_templates`, `contracts`, `contract_signatures`, `additional_drivers`.
- Enums: `BookingStatus`, `ContractStatus`, `SignatureMethod`, `BlockReason`, `ExtraPricingUnit`.
- **Double-booking prevention (REQ-05):**
  ```sql
  CREATE EXTENSION IF NOT EXISTS btree_gist;
  ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
    EXCLUDE USING gist (car_id WITH =,
                        tstzrange(pickup_at, expected_return_at, '[)') WITH &&)
    WHERE (status IN ('confirmed','active','overdue'));
  ```
  plus the equivalent on `car_blocks`, and a trigger checking the two against each other.
- `BookingAvailabilityService` — pre-check for UX, `23P01` translation for the guarantee.
- `PricingService` — rate tiers, extras, discount, tax, deposit; `BookingQuote` snapshotted onto the
  booking at confirmation.
- **`BookingCalendarPage`** — resource timeline, one row per car, drag-to-create, colour by status,
  maintenance blocks inline.
- Booking wizard: customer → car (availability-filtered) → dates → extras → quote → confirm.
- Checkout/check-in flows with `condition_reports` (odometer, fuel, damage diagram, photos, signature).
- `ContractService` — template render, `content_snapshot` freeze, PDF, `amend()`, `close()`.
- `SignatureService` — OTP and drawn signature, per-signer SHA-256 document hash.
- `MessagingService` v1 — email + WhatsApp delivery of the contract PDF, logged to `notification_logs`.
- **Ledger wiring (uses Phase 4):** `BookingPoster` posts E02–E08 at contract activation and closeout;
  deposits post via `DepositPoster` — the `deposits` table and full lifecycle land in Phase 6, so
  Phase 5 handles only the simple "take a deposit, refund it in full" path.

**Tests.** **A concurrency test** firing two overlapping bookings in parallel transactions, asserting
exactly one commits. Maintenance block prevents booking. Contract PDF regenerates identically from
`content_snapshot` after the template is edited. Signature hash matches the delivered PDF. Rental
revenue appears in the ledger on activation, not on confirmation.

**Demo.** Book a car from the calendar, try to double-book it and be refused, check the car out,
generate and sign the contract, send it by WhatsApp, check it back in.

---

## Phase 6 — Payments, Deposits, Owner Instalments, Fines & Payroll

**Goal.** Every remaining money flow is recorded, through the Phase 4 ledger.
**Depends on.** Phases 4, 5.
**Closes.** REQ-07 · REQ-14 · REQ-15 · REQ-03 *(payment side)* · ADV-07

**Deliverables**
- Tables: `payments`, `payment_schedules`, `deposits`, `deposit_deductions`, `owner_installments`,
  `fines`, `employees`, `payroll_runs`, `payroll_items`, `employee_advances`, `commissions`.
- Enums: `PaymentMethod` (cash, bank_transfer, ccp, card, baridimob, cheque, compensation),
  `PaymentStatus`, `DepositStatus`, `DeductionReason`, `InstallmentStatus`, `FineType`,
  `FineLiability`, `FineStatus`, `PayrollStatus`.
- Posters: `PaymentPoster`, `DepositPoster`, `OwnerInstallmentPoster`, `FinePoster`, `PayrollPoster` —
  implementing matrix rows E10–E63.
- `DepositService` — hold / deduct / refund / forfeit, with the "deductions cannot exceed the deposit"
  rule and overflow to receivable.
- `OwnerStatementService` — monthly instalment generation from active agreements (scheduled), payment
  recording, balance from account 2200.
- `FineLiabilityService` — matches `violation_at` against active contracts and **proposes** liability;
  a human confirms.
- Payroll: run creation, per-employee items, commission accrual from bookings, advance recovery,
  approve → pay.
- Resources for all of the above; deposit panel on the contract page; instalment worklist.
- Partial payments and instalment plans: the schedule drives due dates; **balances stay derived** —
  no `paid_amount` column is introduced anywhere.

**Tests.** Every remaining posting-matrix row. A held deposit never appears in revenue. Deductions
cannot exceed the deposit. Owner balance equals accrued minus paid. A customer-liable fine leaves
profit unchanged until it is written off. An advance is an asset, not a salary expense. Sum of partial
payments equals the invoice, and the derived balance reaches exactly zero.

**Demo.** Take a 40% deposit and two instalments on one rental; deduct damage from the deposit at
return; pay an owner's monthly rent; assign a speed camera fine to the customer who had the car.

---

## Phase 7 — Dashboards, KPIs & Charts

**Goal.** The manager opens one screen and knows how the business is doing.
**Depends on.** Phases 4, 5, 6.
**Closes.** REQ-01 · REQ-10 *(UI)* · REQ-11 · REQ-18

**Deliverables**
- `ReportService` — all aggregation methods from [`03-service-layer.md`](03-service-layer.md),
  implementing the reference queries in [`05-accounting-model.md`](05-accounting-model.md).
- Daily widgets: available / rented / maintenance counts, due-returns table, upcoming pickups,
  overdue returns, daily revenue, daily expenses, daily net profit, cash on hand.
- Monthly widgets: revenue-vs-expense chart, net profit trend, cash flow, occupancy gauge,
  top cars by profit, top customers, expense breakdown donut, receivables ageing.
- **Car page Profitability tab** (REQ-11): revenue, expenses, net profit, rental days, utilisation %.
- **Customer page Financials tab**: invoiced, paid, owed, deposits held, fines.
- Role-based widget visibility; branch and date-range filters.
- Caching with a `TransactionPosted`-triggered flush. Add `ledger_daily_balances`
  **only if measurement shows it is needed** — and only as a rebuildable cache.

**Tests.** Each KPI matches a hand-computed value on a seeded fixture. Per-car profit equals
revenue − expenses for that car. Occupancy matches a manually-calculated fixture. Cache invalidates on
a new posting. A receptionist cannot see profit widgets.

**Demo.** A dashboard populated by the data created in Phases 5–6, with figures traceable to
individual ledger rows.

---

## Phase 8 — Notifications & Alerts

**Goal.** The system tells staff, customers and owners what needs attention, before it is late.
**Depends on.** Phases 2, 3, 5, 6.
**Closes.** REQ-17 · REQ-12 *(alerts)* · REQ-13 *(alerts)* · ADV-05

**Deliverables**
- Tables: `alert_rules`, `notification_logs`; Laravel `notifications`.
- `NotificationService` — hourly scheduled rule evaluation.
- `MessagingService` full build: mail, WhatsApp Cloud API, SMS gateway; per-locale templates
  (ar/fr/en); queued with retry; delivery webhooks updating `notification_logs`.
- Alert types: return due tomorrow · booking overdue · payment overdue · owner instalment due ·
  insurance / registration / inspection expiring · driving licence expiring · maintenance due (km or
  date) · recurring expense due · cash variance detected.
- **Deduplication** against `notification_logs` within `repeat_every_days` — an insurance policy
  expiring in 30 days must produce a handful of alerts, not thirty. Without this the feature is worse
  than useless: staff learn to ignore it, and then miss the one that mattered.
- In-app notification bell on all three panels; per-user daily digest option.
- `AlertRuleResource` so lead times are managed by the manager, not by a developer.

**Tests.** Each rule fires at the right lead time and not before. Deduplication verified. A failed
WhatsApp send is retried and logged, and does not block the request. Recipients are correctly scoped —
an owner alert never reaches another owner.

**Demo.** Set an insurance policy to expire in 7 days; watch the alert arrive by email and WhatsApp and
appear in the bell, once.

---

## Phase 9 — Reports & Exports

**Goal.** Anything on screen can be exported as a document.
**Depends on.** Phase 7.
**Closes.** REQ-16

**Deliverables**
- `ReportsHubPage` with a parameter form per report.
- Reports: profit & loss, expense report (by category, by car, by branch), customer report
  (activity + balances), fleet report (utilisation + profitability per car), tax report, cash flow,
  owner statements, receivables ageing, cash session audit.
- PDF export: branded, per-locale, Arabic RTL correct.
- Excel/CSV export with multiple sheets where useful.
- Queued generation for large ranges, with a notification and a download link when ready.
- Saved report configurations and optional scheduled email delivery.

**Tests.** Exported totals match the on-screen figures exactly. Arabic PDFs render RTL correctly.
A 3-year export completes on the queue without timing out. Report data respects the requester's
role and branch scope.

**Demo.** Export last month's P&L to PDF and the fleet profitability report to Excel.

---

## Phase 10 — Portals, Audit, Backups, Multi-branch

**Goal.** External users get their own doors, and the system becomes operationally safe to run.
**Depends on.** All previous phases.
**Closes.** REQ-19 · ADV-03 · ADV-04 · ADV-06 · ADV-08 · ADV-09

**Deliverables**

*Owner portal (REQ-19, ADV-08)*
- Portal-specific read-only resources: `MyCarsResource`, `MyInstallmentsResource`,
  `MyPaymentsResource`, `MyDocumentsResource`, `MyStatementsPage`, `MyProfilePage`.
- All four isolation layers from [`02-filament-panels.md`](02-filament-panels.md).
- Owner widgets; monthly statement PDF via `OwnerStatementService`.
- Owner invitation flow: create a `car_owner` user linked to `car_owners.user_id`.
- **Confirm the disclosure level** flagged in `02` before building the statement.

*Client portal (ADV-09)*
- `MyBookingsResource`, `MyContractsResource` (including the signature landing page),
  `MyInvoicesResource`, `MyFinesResource`, `MyProfilePage`; client widgets.

*Audit (ADV-03)*
- Spatie Activitylog on every model that matters, with old/new values; `ActivityLogResource`
  (view-only, filter by user / model / date); rejected ledger-mutation attempts logged;
  `branch_id` on log rows.

*Backups (ADV-04)*
- `spatie/laravel-backup` scheduled: nightly database, weekly full including media; off-site
  destination; retention policy; failure alerts to the manager; **`BackupService::verifyLatest()`
  restoring into a scratch database and asserting row counts** — a backup that has never been restored
  is only a hypothesis.

*Multi-branch enforcement (ADV-06)*
- Turn on the `BranchScope` global scope; branch switcher for multi-branch users;
  per-branch cash boxes, sequences and reports; cross-branch booking (pick up at A, return at B);
  consolidated vs per-branch dashboards.

**Tests.** The full portal isolation suite from [`02-filament-panels.md`](02-filament-panels.md) —
these become permanent regression tests. Audit log captures create/update/delete with old and new
values. A restore from backup produces a working database. Branch scoping holds on every resource,
widget and export.

**Demo.** Log in as a car owner and see only their cars and money; log in as a customer and sign a
contract; show an audit trail entry; restore a backup.

---

## Cross-phase standing rules

Applies to every session, no exceptions.

1. **Only `AccountingService` writes to `transactions`.** If a phase needs a new money event, add a
   Poster and a row to the matrix in [`05-accounting-model.md`](05-accounting-model.md).
2. **No stored balances.** If you find yourself adding `paid_amount`, `total_revenue` or
   `current_balance`, write the query instead. The banned-columns list is in
   [`01-database-schema.md`](01-database-schema.md).
3. **`branch_id` on every new operational table**, from Phase 1 onward.
4. **Update the docs in the same session as the code.** A schema change that is not reflected in
   `01-database-schema.md` will be contradicted by the next phase.
5. **Each phase ships its tests.** Especially the concurrency test in Phase 5 and the posting-matrix
   tests in Phases 4 and 6 — these are where silent money bugs live.
6. **Every model that touches money or documents gets an activity log** from the phase that creates it,
   not retroactively in Phase 10.

## How to run a phase session

Open a fresh session and give it: the phase number, `docs/00`, `docs/01`, `docs/05` (if the phase
touches money), and the relevant section of `docs/02` and `docs/03`. Ask for the deliverables list,
built in order, with the phase's tests. End the session by updating the docs and ticking the phase's
`REQ-*` IDs in the coverage map in [`00-functional-requirements.md`](00-functional-requirements.md).

## Sequencing at a glance

```
Phase 0  Foundation
   ↓
Phase 1  Auth, Roles, Panels, Branches
   ├──────────────┬──────────────┐
   ↓              ↓              ↓
Phase 2 Fleet   Phase 3 CRM      │
   └──────┬───────┘              │
          ↓                      │
Phase 4 ✓ Ledger + Cash Register ←┘   ← moved ahead of bookings
           ↓
Phase 5  Bookings + Calendar + Contracts
          ↓
Phase 6  Payments, Deposits, Owner Instalments, Fines, Payroll
          ↓
Phase 7  Dashboards + KPIs ──→ Phase 9  Reports
          ↓
Phase 8  Notifications
          ↓
Phase 10 Portals, Audit, Backups, Multi-branch
```
