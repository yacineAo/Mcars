# Mcars — Car Rental Management ERP

Architecture and design documentation. **No implementation code yet** — this folder is the design that
the phased build works from.

A full operations + accounting + fleet + CRM system for a car rental business: three Filament panels
(staff, car owners, customers), a double-entry accounting ledger, a booking calendar with
database-enforced double-booking prevention, e-signed contracts, and per-car profitability.

---

## Read in this order

| # | Document | What it is |
|---|---|---|
| **00** | [Functional Requirements](00-functional-requirements.md) | The 20 requirements + 9 advanced features, as stable IDs (`REQ-01`…, `ADV-01`…), plus a coverage map showing where each is satisfied. |
| **01** | [Database Schema](01-database-schema.md) | Textual ERD, ~50 tables across six modules, with per-module diagrams and the list of columns that deliberately do not exist. |
| **02** | [Filament Panels](02-filament-panels.md) | The admin panel, its resources and every dashboard widget. Staff-only — the owner and client portals were withdrawn (ADR-007). |
| **03** | [Service Layer](03-service-layer.md) | 15 services with responsibilities and signatures, plus the layering convention that keeps models and Filament resources thin. |
| **04** | [Implementation Roadmap](04-implementation-roadmap.md) | 11 phases, each a self-contained session, with deliverables, tests and definition of done. |
| **05** | [Accounting Model](05-accounting-model.md) | Chart of accounts, the full posting matrix (71 business events → exact debit/credit pairs), and every derivation query. |
| **06** | [Design Decisions](06-design-decisions.md) | 14 ADRs recording what was chosen, what was rejected, and why. Plus the open questions. |
| **07** | [Enum Catalogue](07-enums.md) | Every enum and its cases, so phases do not invent divergent values. |
| **08** | [Multi-Branch Retrofit](08-multi-branch-retrofit.md) | Adding `Branch` to an **already-running** single-location system: 5-deploy migration sequence, files to change, the branch switcher, and the modules that break if handled naively. Supersedes ADR-004 for the as-built system. |

**Before an implementation session**, load `00`, `01`, the relevant part of `02`/`03`, and — if the
phase touches money in any way — **all of `05`**.

---

## The invariant everything else follows from

> Every financial event is recorded through the single central `transactions` ledger. Cash balance,
> profit, and per-car profitability are **derived as aggregations over it** — never stored as separate,
> independently maintained totals.

This is why `bookings.paid_amount`, `customers.outstanding_balance` and `cars.total_revenue` do not
exist anywhere in the schema. The full banned-columns list is in
[`01-database-schema.md`](01-database-schema.md).

---

## Confirmed architectural decisions

| Decision | Choice | Why |
|---|---|---|
| **Ledger model** | Two-account double-entry in one `transactions` table: `debit_account_id` + `credit_account_id` + positive `amount` | The only model that keeps security deposits a liability rather than revenue, and makes customer/owner balances derivable instead of stored. [ADR-001](06-design-decisions.md) |
| **Database** | PostgreSQL 16+ | A native `EXCLUDE USING gist` constraint makes double-booking physically impossible, rather than merely guarded in PHP. [ADR-002](06-design-decisions.md) |
| **Multi-branch** | `branch_id` columns from Phase 1, enforcement in Phase 10 | Avoids backfilling an append-only ledger later. [ADR-004](06-design-decisions.md) |
| **Ledger mutability** | Append-only; corrections are reversal entries | An editable ledger is not evidence. [ADR-003](06-design-decisions.md) |
| **Phase order** | Accounting ledger moved **ahead of** bookings/contracts | Bookings take deposits and issue invoices; they need a ledger that already exists. [Roadmap](04-implementation-roadmap.md) |

---

## Stack

- **Laravel** — current stable release. **Verify the version at scaffold time**; Filament v4 requires
  Laravel ≥ 11.28 and PHP ≥ 8.2. Confirm with `composer why-not` rather than assuming.
- **Filament v4** — one staff panel, clusters, resources, widgets
- **PostgreSQL 16+** (`btree_gist`, `pg_trgm`), **Redis** (cache, queue)
- **Spatie** — Permission, Media Library, Activitylog, Backup, Settings
- **Filament Shield** — per-resource permission generation
- **Pest**, **PHPStan**, **Pint**

Locale: Arabic (RTL), French, English. Currency **DZD**. Timezone **Africa/Algiers**.
Payment methods include **CCP** and **BaridiMob**.

---

## Build sequence

```
0  Foundation ...................... scaffold, Docker/Postgres, Money, sequences, enums
1  Auth, Roles, Panels ............. Shield, 3 panel skeletons, branch_id everywhere
2  Fleet ........................... cars, owners, agreements, documents, maintenance
3  CRM ............................. customers, documents, verification
4  Ledger + Cash Register .......... ← moved ahead: transactions, COA, expenses, register
5  Bookings + Contracts ............ calendar, EXCLUDE constraint, PDF, e-signature
6  Payments + Deposits + Owners .... instalments, fines, payroll
7  Dashboards + KPIs ............... widgets, charts, per-car profitability
8  Notifications ................... alert rules, WhatsApp/SMS/Email, deduplication
9  Reports ......................... PDF/Excel exports
10 Audit + Backups ................. activity log, backups, multi-branch enforcement
```

Details, dependencies and per-phase tests: [`04-implementation-roadmap.md`](04-implementation-roadmap.md).

---

## Standing rules for every session

1. Only `AccountingService` writes to `transactions`. New money events get a Poster and a row in the
   posting matrix.
2. No stored balances. If you reach for `paid_amount` or `current_balance`, write the query instead.
3. `branch_id` on every new operational table.
4. Update these docs in the same session as the code — a schema change not reflected here will be
   contradicted by the next phase.
5. Each phase ships its tests. Especially the Phase 5 concurrency test and the Phase 4/6 posting-matrix
   tests.

---

## Open questions

These need answers from the business, but none block starting Phase 0. Full context in
[`06-design-decisions.md`](06-design-decisions.md).

1. **Revenue recognition** — the design books rental revenue at pickup for the full amount. Confirm
   with the accountant **before Phase 4**; changing it afterwards means restating history.
2. **Owner disclosure** — how much does a fixed-rent owner see? Currently: their car's gross rental
   revenue and rental days, but not company margin. Confirm before Phase 10.
3. **TVA / VAT treatment** — account 2400 and the tax report exist; Algerian rental VAT rules need
   confirming before Phase 9.
4. **Depreciation on company-owned cars** — include it, so owned and third-party cars are comparable in
   profitability reports? Recommended, but a business call.
5. **Long-term leasing** — if monthly leases become a product line, both revenue recognition and the
   booking calendar need rethinking.
6. **WhatsApp provider** — official Cloud API (template pre-approval required) or a third-party
   gateway? Affects Phase 5.
7. **Branch count** — if it is genuinely one location forever, Phase 10's multi-branch work can be
   dropped. The columns cost nothing either way.

---

*Design phase. Review and adjust these documents before Phase 0 begins.*
