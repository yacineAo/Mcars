# 08 — Multi-Branch Retrofit (Design)

Layering `Branch` onto an **already-running** single-location system, without breaking it.

> ## Baseline assumption — read first
>
> This document was written **without access to the running codebase**. The `Mcars` repository on this
> machine contains only `docs/` and has no commits; no car rental application was found on the host.
>
> Everything below is therefore written against the schema in
> [`01-database-schema.md`](01-database-schema.md) as the assumed baseline. **Every table, model,
> service and resource name must be verified against the real code before a line is written.** Where
> the as-built system differs, the *sequence* and the *hazards* still hold — the names change.
>
> Two known divergences between the described system and these docs:
> 1. You describe a **single Filament panel**; these docs specify three (admin / owner / client).
>    Section 4 covers both shapes.
> 2. [ADR-004](06-design-decisions.md) put `branch_id` in from Phase 1 specifically to avoid this
>    retrofit. For the as-built system that decision is **superseded** — this document is the record of
>    the path actually taken, and Section 6 is the cost of having deferred it.

---

## 0. Strategy

Five deploys, not one. Each is independently reversible and leaves the system working.

| Deploy | Contains | Reversible by |
|---|---|---|
| **D1** | `branches` table, default branch row, `branch_user` pivot | dropping tables — nothing references them yet |
| **D2** | nullable `branch_id` columns + indexes, everywhere | dropping columns — no code reads them yet |
| **D3** | Backfill of all existing rows (chunked, idempotent, re-runnable) | re-running; it is a no-op on already-filled rows |
| **D4** | Application layer: models, traits, services, resources, policies, switcher UI | feature flag off → behaves exactly as today |
| **D5** | `NOT NULL` constraints, after D4 has run clean in production for a full business cycle | dropping the constraints |

The ordering exists for one reason: **at no point does code that reads `branch_id` run before the data
is there.** D2 and D3 are invisible to the running application. D4 is the only behavioural change, and
it ships behind a flag.

**Feature flag.** `config('branches.enabled')`. When false: no global scope, no switcher, forms
silently default to the branch — the system behaves exactly as it does today. This is what makes D4
safe to deploy and instantly revert without a migration rollback.

---

## 1. Migration files, in order

Names follow Laravel convention; timestamps omitted.

### D1 — Structure with no dependencies

**`create_branches_table`**
```
id, name, code (unique, 2-6 chars, used in document numbering),
address, city, wilaya, phone, email,
manager_user_id  → users.id, nullable, nullOnDelete,
timezone (default Africa/Algiers),
is_active (default true), is_default (default false),
timestamps, softDeletes
```
Partial unique index so only one row can be the default:
`CREATE UNIQUE INDEX branches_one_default ON branches (is_default) WHERE is_default;`

**`seed_default_branch`** — a **data migration**, not a seeder. Seeders do not run on deploy; this must
execute in production automatically and exactly once. Inserts `Main Branch` / code `MAIN` /
`is_default = true`, guarded by `firstOrCreate` so re-running is a no-op.

**`create_branch_user_table`**
```
id, user_id → users (cascade), branch_id → branches (cascade),
is_primary (bool, default false),
unique (user_id, branch_id), timestamps
```

> **Why a pivot *and* a column on `users`.** Requirement 3 offers either. Take both, with a precise
> division of labour — retrofitting the pivot later means rewriting every scope a second time:
>
> - `users.branch_id` = **home branch**. Nullable. Used as the *default value* in forms and as the
>   initial switcher selection. **Never** the access check.
> - `branch_user` = **access grants**. What a user is permitted to see.
> - Resolution rule, implemented once in `User::accessibleBranchIds()`:
>
>   | Condition | Access |
>   |---|---|
>   | has permission `branches.view_all` (manager, super_admin, accountant) | **all branches** — pivot ignored |
>   | pivot has rows | exactly those branches |
>   | pivot empty, `users.branch_id` set | that one branch |
>   | pivot empty, `branch_id` null, no global permission | **none** — deny, and log it |
>
>   That last row matters: the safe failure for an unconfigured staff account is *no data*, not
>   *all data*.

### D2 — Nullable columns (invisible to running code)

Split by module so a single migration failure does not strand the batch. Each adds
`branch_id` (`unsignedBigInteger`, **nullable**, indexed, FK `restrictOnDelete`).

| Migration | Tables |
|---|---|
| **`add_branch_id_to_user_tables`** | `users` — **stays nullable permanently**; null = global access |
| **`add_branch_id_to_fleet_tables`** | `cars`, `maintenance_logs`, `car_ownership_agreements` |
| **`add_branch_id_to_booking_tables`** | `bookings`, `contracts`, `car_blocks` |
| **`add_branch_id_to_finance_tables`** | `transactions`, `payments`, `expenses`, `cash_sessions`, `financial_accounts`, `deposits`, `owner_installments` |
| **`add_branch_id_to_operations_tables`** | `fines`, `notification_logs`, `activity_log`, `payroll_runs`, `employees` |
| **`add_pickup_return_branch_to_bookings`** | `pickup_branch_id`, `return_branch_id` — nullable, for cross-branch rentals (see §4) |
| **`add_composite_branch_indexes`** | the reporting indexes below |

Composite indexes — add these with the columns, not after the first slow dashboard:
```
transactions   (branch_id, occurred_on)
transactions   (branch_id, car_id, occurred_on)
bookings       (branch_id, status, pickup_at)
cars           (branch_id, status)
payments       (branch_id, paid_at)
expenses       (branch_id, incurred_on)
cash_sessions  (branch_id, status)
```
On PostgreSQL, add them `CONCURRENTLY` — which means those statements must run **outside** a
transaction (`public $withinTransaction = false;` on the migration). Forgetting this takes an
`ACCESS EXCLUSIVE` lock on `transactions` for the duration.

**Tables that deliberately get no `branch_id`:**

| Table | Why |
|---|---|
| `customers`, `customer_documents` | A customer registered at one branch **must** be serviceable at another. Branch-scoping customers breaks walk-in business across locations. Add `registered_branch_id` for attribution only — never used for scoping. |
| `car_owners` | An owner may place cars at several branches. Scope their cars, not the owner. |
| `chart_of_accounts` | Company-wide, **except** cash-equivalent accounts, which are per branch (§4). |
| `car_documents`, `booking_extras`, `condition_reports`, `contract_signatures`, `deposit_deductions`, `payroll_items`, `customer_documents` | Pure children. Branch is inherited from the parent via the relation. Duplicating it invites the two copies to disagree. |
| `expense_categories`, `extras`, `contract_templates`, `alert_rules`, `settings` | Configuration. Optional nullable `branch_id` meaning "override for this branch"; null = applies globally. |

### D3 — Backfill

**`backfill_branch_id_on_existing_rows`** — one migration, chunked, idempotent
(`WHERE branch_id IS NULL` throughout, so re-running is safe and resumable).

Order matters — derive from the parent where one exists, so a later branch correction propagates
correctly:

1. `users`, `cars`, `financial_accounts`, `employees` → default branch.
2. `bookings` ← `cars.branch_id` (fall back to default). Also set
   `pickup_branch_id = return_branch_id = branch_id`.
3. `contracts`, `car_blocks` ← `bookings.branch_id`.
4. `maintenance_logs`, `car_ownership_agreements` ← `cars.branch_id`.
5. `cash_sessions` ← `financial_accounts.branch_id`.
6. `payments`, `expenses`, `deposits`, `fines`, `owner_installments`, `payroll_runs` ← parent, else default.
7. **`transactions`** ← `source_type`/`source_id` parent, else `cars.branch_id`, else default. Last,
   because it is the largest table and depends on everything above.
8. Everything still null → default branch. Belt and braces.

> ### ⚠ The `transactions` backfill will fail on first attempt
>
> `transactions` is append-only: an Eloquent guard **and a PostgreSQL trigger** block `UPDATE`
> ([ADR-003](06-design-decisions.md)). The backfill is an `UPDATE`. The trigger will reject it.
>
> This is not a reason to weaken the trigger. Handle it explicitly, inside the migration, in one
> transaction, and log it:
> ```sql
> ALTER TABLE transactions DISABLE TRIGGER transactions_immutable;
> -- chunked UPDATE ... WHERE branch_id IS NULL
> ALTER TABLE transactions ENABLE TRIGGER transactions_immutable;
> ```
> Then assert in the same migration that the trigger is enabled again (`pg_trigger.tgenabled = 'O'`)
> and **fail loudly if it is not**. A silently-left-disabled immutability trigger is the single worst
> outcome of this entire retrofit — the ledger would quietly become editable and nobody would notice
> until an audit.
>
> A `data_migrations` audit row recording who ran it, when, and how many rows changed is worth the ten
> extra lines. This is the one time in the system's life that history is legitimately rewritten.

Run on a **restored production snapshot** first and record the wall-clock time. A multi-million-row
`transactions` backfill can take minutes; you need that number before you schedule the window.

**`recreate_cash_register_entries_view`** — the register is a view
([ADR-008](06-design-decisions.md)). `CREATE OR REPLACE VIEW` can only *append* columns; adding
`branch_id` in position means `DROP VIEW` + `CREATE VIEW`. Trivial, but it must be in the migration
list or the register silently keeps returning branchless rows.

### D5 — Tighten (a separate deploy, days later)

**`make_branch_id_not_null`** — every table **except `users`**.

On PostgreSQL, `SET NOT NULL` takes an `ACCESS EXCLUSIVE` lock and full-scans the table. On a large
`transactions` table that is a production outage. Use the two-step instead:

```sql
ALTER TABLE transactions
  ADD CONSTRAINT transactions_branch_id_not_null CHECK (branch_id IS NOT NULL) NOT VALID;
-- instant, takes only a brief lock

ALTER TABLE transactions VALIDATE CONSTRAINT transactions_branch_id_not_null;
-- scans, but only SHARE UPDATE EXCLUSIVE — reads and writes continue
```

Gate this migration on a precondition query that aborts if **any** row still has a null `branch_id`,
with a clear message naming the table. Do not let a half-backfilled table reach a `NOT NULL`.

---

## 2. Application changes

### 2.1 New

| Class | Purpose |
|---|---|
| `App\Models\Branch` | `hasMany` to Car, Booking, Contract, Transaction, Payment, Expense, CashSession, User, Employee, MaintenanceLog, Fine, Deposit, OwnerInstallment, PayrollRun; `belongsTo` manager; `belongsToMany` staff via pivot |
| `App\Models\Concerns\BelongsToBranch` | Trait: the `branch()` relation, auto-fills `branch_id` from `BranchContext` on `creating`, and applies `BranchScope` |
| `App\Models\Scopes\BranchScope` | Global scope; **no-ops** when the flag is off, when the context is unresolved (console/queue), or when the user has `branches.view_all` and has selected "All" |
| `App\Services\BranchContext` | Singleton. `current(): ?Branch`, `isGlobal(): bool`, `set(?int)`, `accessibleIds(): array`. The **only** place session state is read |
| `App\Http\Middleware\ResolveBranchContext` | Panel middleware: session → validate against `accessibleBranchIds()` → populate `BranchContext` |
| `App\Filament\Resources\BranchResource` | CRUD, gated to `manager` / `super_admin` |
| `App\Livewire\BranchSwitcher` | Topbar component (§3) |
| `App\Policies\BranchPolicy` | |
| Enum `BranchScopeMode` | `global` · `single` |

### 2.2 Models to modify

Add `use BelongsToBranch;` — one line each:

`Car` · `Booking` · `Contract` · `CarBlock` · `MaintenanceLog` · `CarOwnershipAgreement` ·
`Transaction` · `Payment` · `Expense` · `CashSession` · `FinancialAccount` · `Deposit` ·
`OwnerInstallment` · `Fine` · `PayrollRun` · `Employee` · `NotificationLog`

`User` is different — it gets `branch()`, `branches()` (pivot), `accessibleBranchIds()` and
`hasGlobalBranchAccess()`, but **not** `BranchScope`. Scoping the users table by branch means an admin
can lock themselves out of user management.

`Customer` and `CarOwner` get **no trait** — see the D2 exclusion table.

### 2.3 Services

| Service | Change |
|---|---|
| **`AccountingService`** | `TransactionDraft` gains a **required** `branchId`. `post()` throws `MissingBranchException` if it is null — a branchless ledger row is unattributable and silently corrupts every per-branch report. Resolve from the source document first, `BranchContext` second, never from the default branch as a fallback. |
| **`CashRegisterService`** | Every method takes a branch. `balance()` is per financial account, and accounts are per branch. `openSession()` must reject opening a session on another branch's account. |
| **`ReportService`** | Every method gains `?Branch $branch = null` — **null means company-wide**, preserving today's behaviour for every existing caller. New: `revenueByBranch()`, `profitByBranch()`, `occupancyByBranch()` for the breakdown tables. |
| **`BookingAvailabilityService`** | `availableCars()` gains a branch filter, defaulting to the context branch. The `EXCLUDE` constraint is unchanged — a car is one physical object and cannot be in two places regardless of branch. |
| **`SequenceGenerator`** | Per-branch numbering. See §4 — the riskiest small change in the retrofit. |
| **`PricingService`** | Only if rates become per-branch. Otherwise untouched. |
| **`NotificationService`** | Recipient resolution scoped by branch — a Branch A cash variance must not page Branch B's manager. |
| **`OwnerStatementService`** | Statements stay owner-scoped and span branches. **Do not add branch filtering** — an owner with cars at two branches must receive one statement. |

### 2.4 Filament resources

| Resource | Change |
|---|---|
| `CarResource` | Branch `Select` (required, defaults to context, disabled when the user has one branch); branch column + filter; **transfer action** rather than free editing (§4) |
| `BookingResource` | Branch `Select`; `pickup_branch_id` / `return_branch_id`; availability filtered to branch |
| `UserResource` | Home-branch `Select` + `CheckboxList` for pivot grants; "Global access" toggle mapping to null + permission |
| `EmployeeResource`, `ContractResource`, `MaintenanceLogResource`, `ExpenseResource`, `PaymentResource`, `FineResource`, `DepositResource`, `OwnerInstallmentResource`, `PayrollRunResource`, `CashSessionResource`, `FinancialAccountResource` | Branch column + filter; hidden field auto-filled from context |
| `TransactionResource` | Branch column + filter only — it stays view-only |
| `CustomerResource`, `CarOwnerResource` | **Unchanged.** Deliberately company-wide |
| All list tables | `SelectFilter::make('branch_id')`, visible only to users with more than one accessible branch |

Because `BranchScope` is a global scope, **existing `getEloquentQuery()` overrides need no change** —
scoping arrives automatically. This is the main reason to use a global scope rather than editing forty
resources.

### 2.5 Policies

Do **not** rewrite policies to check branch. The global scope handles list visibility; policies handle
direct-ID access. Add one trait, applied to the staff-side policies:

```php
trait ChecksBranchAccess {
    protected function sharesBranch(User $u, Model $m): bool {
        return $u->hasGlobalBranchAccess()
            || in_array($m->branch_id, $u->accessibleBranchIds(), true);
    }
}
```
Called from `view`, `update`, `delete` on: Car, Booking, Contract, Transaction, Payment, Expense,
CashSession, MaintenanceLog, Fine, Deposit, OwnerInstallment, PayrollRun, Employee.

`CustomerPolicy` and `CarOwnerPolicy` are untouched. **`CarOwnerPolicy` and the portal policies must
not gain branch checks** — see §4.

---

## 3. The branch switcher

### Why not Filament's native tenancy

Filament v4 ships multi-tenancy (`->tenant(Branch::class)`), which is the obvious first answer and the
wrong one here:

- It puts the tenant in the URL (`/admin/1/cars`), **breaking every existing bookmark, deep link and
  saved report URL**. The brief says non-breaking.
- It has **no "all tenants" mode**. Requirement 5 needs Manager/Admin to see aggregated global KPIs,
  which is the primary reason multi-branch is being added at all.
- Every resource must become tenant-aware, including the owner and customer portals, which must not be
  branch-scoped at all.

Native tenancy is right for SaaS where tenants are strictly isolated. Here branches are *departments of
one company* with a global view above them. **Recommendation: session-scoped branch context.**

### Component and placement

A Livewire component rendered into the panel topbar via a render hook — no layout override, so it
survives Filament upgrades:

```php
$panel->renderHook(
    PanelsRenderHook::TOPBAR_START,
    fn () => Blade::render('@livewire("branch-switcher")'),
);
```

A `Select` showing:
- **All Branches** — only for users with `branches.view_all`
- each accessible branch, by name

Hidden entirely when the user has exactly one accessible branch — a dropdown with one option is noise.

### Persistence and resolution

State lives in the **session** (`branch_context.active_id`), not on the user record: a manager glancing
at Branch B should not permanently change their profile, and two browser tabs are two contexts.

```
request → ResolveBranchContext middleware
        → read session key
        → validate against user->accessibleBranchIds()   ← re-validated every request
        → populate BranchContext singleton
        → BranchScope reads BranchContext
```

Re-validating on every request is what stops a stale session from outliving a revoked grant.
`accessibleBranchIds()` is cached per-request only.

On change: write session, then `$this->dispatch('branch-changed')` and a full page refresh. Widgets
listen and re-query. A soft Livewire refresh leaves cached widget data stale — see the cache hazard in §4.

### Where the context is *not* applied

This is the part that causes production bugs if left implicit:

| Context | Behaviour |
|---|---|
| Queued jobs | **No scope.** No session exists. Jobs must receive an explicit `branch_id` in their payload. A global scope that silently resolves to "no branch" inside a job produces wrong data with no error. |
| Console commands / schedulers | **No scope.** Nightly instalment generation, alerts and integrity checks are company-wide by definition. |
| Owner / customer portals | **Scope disabled entirely** (§4) |
| Model factories, tests | Explicit branch, always |

Implement this as an explicit allow-list — the scope applies only when `BranchContext` has been
populated by the middleware. Fail toward *unscoped* in background contexts and *denied* in HTTP
contexts.

---

## 4. Modules needing special attention

Ranked by how badly they break if handled naively.

### 4.1 Accounting — inter-branch transfers ⚠ highest risk

Branch A sends 200 000 DZD cash to Branch B. Post it as a single transaction
(`Dr Cash-B / Cr Cash-A`) and it carries **one** `branch_id`. Whichever branch it is stamped with, the
other branch's cash balance is now wrong, and no per-branch report will reconcile.

**Fix:** add an inter-branch clearing account (`2600 Inter-Branch Clearing`) and post **two** rows
sharing a `group_uuid`:

```
Dr 2600 Inter-Branch Clearing / Cr 1010 Cash    branch_id = A
Dr 1010 Cash / Cr 2600 Inter-Branch Clearing    branch_id = B
```

Each branch's books balance independently; account 2600 nets to zero company-wide, and a non-zero
balance is a red flag worth an integrity check. **Company-wide reports must exclude 2600**, or
turnover is inflated by every internal movement.

Also required:
- **Cash accounts become per branch.** `1010` splits into `1010-MAIN`, `1010-ORAN`, … Each branch's
  `financial_accounts` rows point at its own COA account. Without this, "cash on hand" is a single
  company number and the register is meaningless per location.
- Revenue and expense accounts stay **shared**; the `branch_id` dimension does the splitting.
- Add integrity checks: per-branch trial balance sums to zero; account 2600 nets to zero.

### 4.2 Cash register

The most operationally sensitive module. A receptionist seeing another branch's till is an immediate
trust problem.

- `cash_sessions` scoped hard by branch; `financial_accounts` likewise.
- The "one open session per account" partial unique index still works — accounts are already
  per-branch after 4.1.
- **The view must be recreated** to expose `branch_id` (D3).
- Opening float, banking and transfers all become inter-branch entries per 4.1.
- Test explicitly: Branch A staff cannot open, view, close or reconcile a Branch B session.

### 4.3 Document numbering ⚠ subtle and irreversible

Contract numbering goes from company-wide to per-branch. Naive per-branch sequences restart at 1 and
**collide with existing contract numbers**. Contract numbers are legal identifiers; duplicates are not
a cosmetic problem.

**Fix:** change the *format*, not the counter. `CTR-2026-000123` → `CTR-MAIN-2026-000124`,
`CTR-ORAN-2026-000001`. Historical numbers are never touched. Seed each new branch sequence from 1 —
the branch code makes collision impossible. Keep a `unique` index on `contract_number` as the backstop
and test the cutover on a snapshot.

### 4.4 Cars moving between branches

A car transfers from Branch A to Branch B. Editing `cars.branch_id` in a form silently rewrites which
branch "owns" a car with live bookings and a year of history.

- Replace free editing with a **Transfer** action: effective date, reason, actor, logged to
  `activity_log`, blocked while the car is `rented` or has confirmed future bookings at the origin.
- **Historical transactions keep the branch they were posted under.** Do not retro-move them; Branch A
  genuinely earned that revenue. This means per-car profitability spans branches — correct, and worth
  stating in the report so nobody reads it as a bug.
- Optional `car_branch_transfers` history table if transfers become routine.

### 4.5 Cross-branch bookings

Pick up at Branch A, return at Branch B — a real requirement for any multi-location rental business.

- `bookings.branch_id` = the branch that **sold** the rental and owns the revenue.
- `pickup_branch_id` / `return_branch_id` = logistics.
- On return at B, the car's location changes but not its owning branch (4.4).
- The Branch B "due for return today" widget must query `return_branch_id`, **not** `branch_id`, or
  staff will not see cars physically arriving at their counter. Easy to get wrong; a specific test.

### 4.6 Owner and customer portals ⚠ silent breakage

Requirement 3 says portals are unaffected. That is not automatic — it is the opposite of automatic.

A global `BranchScope` on `Car` applies to **every** query, including the owner portal's. A car owner
has no branch context, so `BranchContext` resolves to nothing, and depending on the scope's fallback
their cars either vanish or all of them appear.

**`BranchScope` must be disabled outright when the request is on the owner or client panel** (or, in a
single-panel system, when the user holds `car_owner` or `client`). Explicit check in the scope, plus a
regression test asserting an owner with cars at two branches sees both. In a single-panel system this
is more dangerous, not less — one wrong global scope reaches every role at once.

### 4.7 Dashboard caching ⚠ cross-branch data leak

Widget caches keyed by date and metric alone will serve **Branch A's numbers to Branch B**. Every cache
key must include the branch context (or `global`), and `TransactionPosted` must flush both the posting
branch's key and the global key.

Beyond wrong numbers, this is a confidentiality failure — the exact thing branch scoping was added to
prevent. Worth an explicit test that primes the cache as one branch and asserts a different branch gets
different figures.

### 4.8 Reports

- Optional `?branch_id`: present → filtered; absent → company-wide **plus** a per-branch breakdown table.
- Company-wide totals must exclude inter-branch clearing (4.1).
- Report headers must state the branch, or "All Branches". A PDF that does not say which branch it
  covers becomes unusable the moment it leaves the screen.
- Queued exports carry the branch in the job payload — there is no session on the queue (§3).

### 4.9 Employees, roles and lockout

- `users.branch_id` stays nullable forever; null + `branches.view_all` = global.
- The resolution table in §1 denies an unconfigured account rather than granting everything.
- **Verify existing Manager/Admin users end up with `branches.view_all`** — the backfill assigns them
  to Main Branch, and if the permission is missing they are silently scoped to one branch. This is
  requirement 6's "no accidental data hiding", and it is the single most likely day-one incident.
- `BranchResource` permissions granted to `manager`/`super_admin` only. Shield must be re-run.

### 4.10 Notifications, alerts, audit

- Alert recipients resolved per branch; `alert_rules.branch_id` null = global rule.
- `notification_logs.branch_id` for per-branch delivery audit.
- `activity_log` gains `branch_id`, or per-branch audit becomes a join through polymorphic subjects.

---

## 5. Verification (requirement 6)

Run against a **restored production snapshot**, not a seeded database.

### Pre-cutover
```sql
-- must all return 0, per table
SELECT count(*) FROM transactions WHERE branch_id IS NULL;
SELECT count(*) FROM bookings     WHERE branch_id IS NULL;
-- …every table from D2

-- ledger totals unchanged by the backfill
SELECT sum(amount) FROM transactions;   -- compare to the pre-migration value

-- immutability trigger re-enabled  ⚠
SELECT tgname, tgenabled FROM pg_trigger WHERE tgname = 'transactions_immutable';
-- tgenabled must be 'O'

-- no orphans
SELECT count(*) FROM transactions t
  LEFT JOIN branches b ON b.id = t.branch_id WHERE b.id IS NULL;
```

### Regression tests (permanent)
```
manager sees all branches by default; global KPIs match pre-migration values exactly
receptionist at Branch A sees zero Branch B cars, bookings, cash sessions
receptionist at Branch A gets 403 on a Branch B record by direct ID
car owner with cars at two branches sees both          ← the portal-scope trap
customer sees their bookings regardless of branch
dashboard cache primed as Branch A returns different figures for Branch B
inter-branch transfer leaves both branches balanced; account 2600 nets to zero
company-wide revenue excludes inter-branch clearing
contract numbers never collide across branches
Branch B "due for return today" includes a car picked up at A, returning to B
queued export produces the same figures as the on-screen report
an unconfigured staff account sees nothing, not everything
```

### Cutover
Maintenance window sized from the **measured** snapshot backfill time, not an estimate. D1–D3 run in
the window; D4 deploys with the flag off; the flag is turned on once the checks above pass. D5 runs
days later.

**Rollback:** flag off restores previous behaviour instantly without touching the database. The
migrations are only rolled back if D1–D3 themselves fail, and they are ordered so each is
independently reversible.

---

## 6. What deferring this cost

Recorded plainly, because it is the clearest evidence for [ADR-004](06-design-decisions.md) and worth
knowing before the next such decision.

Had `branch_id` been added from Phase 1 (nullable, defaulted, unenforced), this work would be:
turn on the scope, add the switcher, add the filters — roughly Section 2.4 and Section 3, a few days.

Because it was deferred, it additionally requires: a five-deploy sequence, a chunked backfill of every
table, **temporarily disabling the ledger's immutability trigger**, a `NOT NULL` migration that must
avoid locking `transactions`, a document-numbering format change to dodge collisions, a chart-of-
accounts split for per-branch cash, and a maintenance window.

The nullable column would have cost one line per migration.

**ADR-004 is superseded for the as-built system**, and should be amended to record that the retrofit
path was taken, what it cost, and that `06-design-decisions.md` now points here.
