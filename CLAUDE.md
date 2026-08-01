# Mcars — Car Rental Management ERP

Laravel 13 + Filament 5 + PostgreSQL 16. Operations + accounting + fleet + CRM for a car rental
business in Algeria (DZD, CCP, BaridiMob, wilaya; ar/fr/en with Arabic RTL contracts).

**The design lives in `docs/`. Read it before changing anything.**

| Doc | When you need it |
|---|---|
| [`docs/README.md`](docs/README.md) | Index, stack, open questions |
| [`docs/00-functional-requirements.md`](docs/00-functional-requirements.md) | `REQ-*` / `ADV-*` IDs + coverage map |
| [`docs/01-database-schema.md`](docs/01-database-schema.md) | Every table. **Read before any migration.** |
| [`docs/02-filament-panels.md`](docs/02-filament-panels.md) | The admin panel (staff-only), resources, widgets |
| [`docs/03-service-layer.md`](docs/03-service-layer.md) | Services and the layering convention |
| [`docs/04-implementation-roadmap.md`](docs/04-implementation-roadmap.md) | The 11 phases |
| [`docs/05-accounting-model.md`](docs/05-accounting-model.md) | **Read in full before touching money.** |
| [`docs/06-design-decisions.md`](docs/06-design-decisions.md) | ADRs — argue with these before overriding them |
| [`docs/07-enums.md`](docs/07-enums.md) | Enum catalogue |
| [`docs/08-multi-branch-retrofit.md`](docs/08-multi-branch-retrofit.md) | Only if retrofitting a branchless system |

---

## The invariant everything follows from

> Every financial event is recorded through the single central `transactions` ledger. Cash balance,
> profit and per-car profitability are **derived as aggregations over it** — never stored.

Consequences that are not negotiable:

1. **Only `AccountingService` writes to `transactions`.** New money event → new Poster + a row in the
   posting matrix in `docs/05-accounting-model.md`.
2. **The ledger is append-only.** No update, no delete, no soft delete. Corrections are reversal rows.
3. **No stored balances.** These columns must never exist: `bookings.paid_amount`,
   `customers.outstanding_balance`, `cars.total_revenue`, `financial_accounts.current_balance`,
   `owner_installments.amount_paid`. Write the query.
4. **A security deposit is a liability, never revenue.**
5. Derived caches are allowed only if truncating and rebuilding them loses no information.

---

## Running it

You can use either `docker compose` directly or the `./sail` wrapper (Laravel Sail):

```bash
# Start everything
docker compose up -d
# or
./sail up -d

# Run artisan commands
docker compose exec app php artisan migrate --seed
# or
./sail artisan migrate --seed

# Run tests
docker compose exec app ./vendor/bin/pest
# or
./sail pest

# Static analysis
docker compose exec app ./vendor/bin/phpstan analyse
# or
./sail phpstan analyse

# Code style
docker compose exec app ./vendor/bin/pint
# or
./sail pint
```

| Service | URL |
|---|---|
| App | http://localhost:8000 |
| Mailpit | http://localhost:8025 |
| Postgres | localhost:5432 — `mcars` / `mcars` / `secret` |

Tests use a separate `mcars_testing` database. Create it once:
`docker compose exec pgsql createdb -U mcars mcars_testing`.

> If the Docker daemon is reached through a mounted socket from another container, run
> `docker compose` **on the host** — bind-mount paths in `docker-compose.yml` resolve against the
> host filesystem, not the calling container's.

---

## Conventions

### Database
- **PostgreSQL only.** MySQL cannot express the bookings `EXCLUDE` constraint (ADR-002).
- Money: `decimal(18,2)`, cast through `MoneyCast`. **Never `float`.**
- Moments: `timestampTz()`. Accounting dates: `date` (`occurred_on`).
- App timezone is `Africa/Algiers`, not UTC — a payment at 00:30 must land on that day's revenue.
- Soft deletes on master data. **Never on `transactions`.**
- `branch_id` (nullable) on every operational and financial table, from the first migration (ADR-004).
- Enums: `varchar` + PHP backed enum + a `check` constraint. Not native PG enums.
- Files: Spatie Media Library on a **private disk**. No `*_path` columns.

### Code layering
| Layer | Owns | Never |
|---|---|---|
| Model | relations, casts, scopes | money math, cross-aggregate writes |
| Action | one use case, `execute()` | orchestrating other use cases |
| Service | orchestration, transaction boundary | knowing about Filament |
| Filament Resource | form + table definition | business rules |

A Filament page never calls `Transaction::create()`, never sums amounts, and never decides whether a
car is available.

### Tests
- **PostgreSQL, not SQLite.** SQLite cannot express the `EXCLUDE` constraint, the ledger trigger,
  partial unique indexes or `timestamptz` — the suite would go green while proving nothing.
- `RefreshDatabase` wraps Feature tests in a transaction. Anything asserting behaviour at
  transaction level 0 belongs in `tests/Unit` (see `tests/Unit/SequenceGuardTest.php`).
- Every phase ships its tests. Non-negotiable: the Phase 5 concurrency test (two overlapping bookings
  in parallel transactions, exactly one commits) and the Phase 4/6 posting-matrix tests.

---

## Foundation primitives (Phase 0)

| Class | Purpose |
|---|---|
| `App\Support\Money` | Integer minor units. `allocate()` splits instalments without losing a centime. |
| `App\Support\Casts\MoneyCast` | `decimal(18,2)` ↔ `Money` |
| `App\Support\Period` | Date ranges. `[start, end)`, calendar-aware `previous()`. |
| `App\Support\Sequences\SequenceGenerator` | Gap-free document numbers. **Must run inside a transaction** — it throws otherwise. |
| `App\Models\Concerns\BelongsToBranch` | Branch relation + auto-fill. The restricting scope arrives in Phase 10. |
| `App\Models\Concerns\HasAuditColumns` | `created_by_id` / `updated_by_id` |
| `App\Enums\Concerns\HasEnumMeta` | Translated labels, Filament colours/icons, `options()` |

---

## Current state

Phases 0–8 complete. Next: **Phase 9 — reports and exports**.

**The system is staff-only and charges no tax.** Two scope decisions taken after Phase 8, both of
which delete rather than add:

- **No customer or car-owner logins.** One Filament panel (`admin`); every `UserRole` case is a staff
  role. The owner and client portals, their middleware and the four-layer isolation model are gone —
  ADR-007 records what was removed and how to restore it. `car_owners.user_id` and
  `customers.user_id` are kept but unused. Anything a customer or owner needs to know goes to the
  office, which tells them; alert recipients therefore resolve from staff roles only.
- **No tax anywhere in the money path.** `bookings.tax_rate`/`tax_amount`, `expenses.tax_amount`,
  posting E03, `TransactionType::Tax` and account 2400 (TVA) do not exist. A booking total is
  `subtotal + extras − discount`, full stop. `tax_id` (NIF) was dropped from customers, owners and
  vendors too. Two things that merely *sound* like tax are deliberately kept: `cars.road_tax_expiry_date`
  (the vignette, a document-expiry alert) and account 5060 Taxes & Registration (an expense category
  for registration costs the business does pay).

The `branches` table, `Branch` model and `BranchSeeder` were built in Phase 0 rather than Phase 1,
because `BelongsToBranch` and per-branch document numbering both need them to exist.

Two conventions established in Phase 7 that later phases must follow:

- **Every ledger aggregation goes through `ReportService`.** Widgets, pages and (from Phase 9)
  exports call it; none of them sum `transactions` themselves. Adding a figure means adding a method
  there, not a query in a widget.
- **`reports.view_financials` gates money.** Revenue, profit, cash flow and receivables are hidden
  behind this permission (super_admin, manager, accountant). Check the permission — never a role list.
  `branches.view_all` governs cross-branch visibility, and a user without it is pinned to their own
  branch server-side regardless of any filter they submit.

`utilisation_pct` and occupancy both divide by **calendar days**, not availability-adjusted days —
see `docs/tasks/phase-07-dashboards.md`.

Three conventions established in Phase 8:

- **Alerts leave through `MessagingService` only.** Channels are backed by `MessageDriver`
  implementations registered in `config/notifications.php` (mail, in-app, Discord). Nothing else
  talks to a provider, and nothing talks to one from a request — every send is queued, because a
  provider timeout must never stall a receptionist mid-checkout. Adding WhatsApp or SMS is a driver
  class, an enum case, a migration widening the `channel` CHECK constraint, and a config line.
- **Deduplication is correctness, not optimisation (ADR-012).** The window counts `Queued` and
  `Sending` alongside `Sent` — an alert on the queue is one the recipient is about to get. A channel
  whose driver is off is not queued at all, since a cancelled row deliberately does not hold the
  window shut. Never key dedup on `sent_at`.
- **`car_owner` and `client` are subject-bound roles.** A rule naming one resolves only to the users
  the subject points at, never to every holder of the role. Staff roles fan out across the branch;
  portal roles never do.

`AlertRule` deliberately overrides `BelongsToBranch::resolveBranchId()` to return null — a null
`branch_id` there means "all branches", not "fill this in". Phase 10's branch scope must leave
`alert_rules` alone for the same reason.

`ReportService::openReceivableForBooking()` / `…ForCustomer()` are deliberately **not** branch-scoped,
unlike every other method on that class. They decide how a payment posting splits between the
receivable and the customer credit balance — arithmetic, not permission. Scoping them to the
operator's branch would under-report the receivable whenever a booking's revenue and payment rows sit
on different branches, and the shortfall would silently land on 2500 instead of clearing the invoice.
Phase 10 must leave these two alone.
