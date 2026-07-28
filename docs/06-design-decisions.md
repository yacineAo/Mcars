# 06 — Design Decisions (ADRs)

Short records of each significant choice: the context, the decision, what it costs, and what was
rejected. When a later phase wants to do something differently, this is the file to argue with.

---

## ADR-001 — Two-account double-entry ledger, in one table

**Status.** Accepted (confirmed with the user).

**Context.** The requirement is a single central `transactions` table from which cash balance, profit
and per-car profitability are derived, with no independently maintained totals. The obvious
implementation is a flat table of income/expense rows with a signed amount.

**Decision.** `transactions` carries `debit_account_id`, `credit_account_id` and a always-positive
`amount`, against a seeded `chart_of_accounts`. Still one table, as required.

**Consequences.**
- Security deposits are a liability (2100), not revenue — profit is not overstated by money the company
  is merely holding.
- Customer amounts owed (REQ-04) and owner remaining balances (REQ-03) are account balances, not
  columns — the constraint is satisfied rather than worked around.
- A fine paid to the authority and recharged to the customer touches neither revenue nor expense.
- Cost: staff never see accounts (the UI shows "Main Cash Box", not `1010`), but developers must
  consult the posting matrix rather than inventing entries. Every new money event needs a matrix row.
- Events needing three or more legs are posted as several balanced rows sharing a `meta->group_uuid`,
  written atomically. This is the one real ergonomic cost of the two-column shape.

**Rejected: signed single-account ledger.** Simpler to build and to display, but it cannot represent
deposits, receivables or payables without side tables carrying their own running totals — which is
exactly what the core constraint forbids. It fails on the first deposit taken.

---

## ADR-002 — PostgreSQL, with a database-level booking exclusion constraint

**Status.** Accepted (confirmed with the user).

**Context.** REQ-05 says the system *must* prevent double-booking. Two receptionists on two machines
can submit overlapping bookings for the same car within the same millisecond.

**Decision.** PostgreSQL 16+, with:
```sql
EXCLUDE USING gist (car_id WITH =, tstzrange(pickup_at, expected_return_at, '[)') WITH &&)
  WHERE (status IN ('confirmed','active','overdue'))
```

**Consequences.**
- The race condition is unreachable. One transaction commits, the other receives `23P01`, which the
  application turns into a friendly validation message.
- Requires the `btree_gist` extension.
- Application-level availability checks remain, but purely for UX. They are not the guarantee, and the
  code should say so in a comment, or a future maintainer will "optimise away" the constraint.
- Postgres also gives `jsonb`, real `numeric` semantics for money, and partial unique indexes (used for
  "one open cash session per account").

**Rejected: MySQL 8 with a `car_reservation_slots` table** (one row per car per rented day, unique on
`(car_id, date)`). Correct and atomic, but it adds a table, a rebuild job, and sub-day-granularity
awkwardness — an 09:00 return and an 11:00 pickup on the same day are legitimate and the slot model
either forbids them or needs hour granularity and 24× the rows.

**Rejected: application-level locking only.** `SELECT … FOR UPDATE` on the car row works if every write
path remembers to take the lock. Some future import script, API endpoint or admin action will not.

---

## ADR-003 — The ledger is append-only

**Status.** Accepted.

**Context.** Corrections happen: wrong amount, wrong car, wrong customer, duplicate entry.

**Decision.** No `UPDATE`, no `DELETE`, no soft delete on `transactions`. Enforced by an Eloquent guard
*and* a Postgres trigger. Mistakes are corrected by posting a reversal (accounts swapped, mandatory
reason, linked in both directions), then posting the correct entry.

**Consequences.**
- Reports are defensible. A figure shown to an owner or a tax inspector cannot have been quietly
  changed afterwards.
- The audit trail shows what someone believed and when they learned otherwise — which is what an
  investigation actually needs.
- Cost: more rows, and a UI that must present reversals clearly so the ledger does not look like it is
  full of duplicates. Reversal is permission-gated to `accountant` and `manager`.

**Rejected: editable rows with an audit log.** The audit log records that a change happened, but the
current row is still the only thing reports read. That is not equivalent, and it means a single
mis-click can silently change last quarter's profit.

---

## ADR-004 — `branch_id` columns from Phase 1, enforcement in Phase 10

**Status.** Accepted for a greenfield build — **superseded for the as-built system**, which was
implemented without branch columns. The retrofit path (the "Rejected" option below) is specified in
[`08-multi-branch-retrofit.md`](08-multi-branch-retrofit.md), including what deferring it cost.

**Context.** Multi-branch is "if needed" (ADV-06), scheduled last. But `branch_id` belongs on nearly
every table.

**Decision.** Add nullable `branch_id` to every operational and financial table from Phase 1, with all
rows defaulting to a single seeded branch. Global scope, branch switcher, per-branch cash boxes and
per-branch numbering stay off until Phase 10.

**Consequences.**
- No data migration when multi-branch is switched on, and specifically **no backfill of an append-only
  ledger** — which would otherwise mean either violating ADR-003 or rebuilding history.
- Cost now is one nullable column per table and a trait. Cost later, if deferred, is a migration
  touching every table plus a numbering-sequence rework.

**Rejected: add it in Phase 10.** Cleaner early schema, materially harder later, and the ledger makes
it worse than a normal retrofit.

---

## ADR-005 — Contracts store an immutable `content_snapshot` and a document hash

**Status.** Accepted.

**Context.** A contract is a legal document. Templates get edited, price lists change, cars get sold,
customers move house. A contract signed in 2026 must still render in 2029 exactly as signed.

**Decision.** `contracts.content_snapshot` (jsonb) holds the fully-resolved terms, parties, vehicle and
prices frozen at generation. The PDF is regenerable from the snapshot alone. Each
`contract_signatures` row stores the SHA-256 of the PDF as it stood when *that party* signed, plus IP,
user agent and timestamp.

**Consequences.**
- Editing a template never alters historical contracts.
- "That is not the document I signed" has a technical answer.
- Cost: some duplication between the snapshot and the live records — deliberate, and the whole point.

---

## ADR-006 — Ownership terms live in `car_ownership_agreements`, not on `cars`

**Status.** Accepted.

**Context.** REQ-03 needs monthly rent, due date and instalment count for third-party cars. The
shortest route is columns on `cars`.

**Decision.** A separate `car_ownership_agreements` table with `start_date`/`end_date`, a `model`
(`fixed_monthly | revenue_share | hybrid`), and an `EXCLUDE` constraint preventing overlapping active
agreements for a car.

**Consequences.**
- Rent changes, owner changes and revenue-share arrangements are all representable, with history.
- Last year's instalments still show the rate that actually applied — putting rent on `cars` would
  rewrite them.
- Supports revenue-share owners, which a single `monthly_rent_amount` column cannot express at all.
- Cost: one more table and a join.

---

## ADR-007 — One panel, staff only. No customer or owner logins.

**Status.** Accepted. **Superseded the original three-panel decision** — see the revision note below.

**Context.** The business is run from the office. Customers and car owners are people the staff deal
with by phone and in person; they are records in the system, not users of it.

**Decision.** One `users` table, one Filament panel (`admin`), and every `UserRole` case is a staff
role. `User::canAccessPanel()` admits any user holding any role. There is no customer or car-owner
login, no owner portal and no client portal.

**Consequences.**
- The entire portal isolation problem disappears. There is no second audience to leak data to, so
  there are no portal panels to keep internal resources off, and no portal isolation tests to
  maintain. Authorisation reduces to permissions inside one panel.
- `car_owners.user_id` and `customers.user_id` are **retained but unused.** They are the seam if a
  portal is ever wanted; keeping the columns costs nothing and dropping them would make re-adding one
  a data migration rather than a feature.
- Anything a customer or owner needs to be told goes **to the office, which tells them.** Phase 8
  alerts therefore resolve recipients from staff roles only.
- Owner statements, ownership agreements and instalments are unaffected: those are things staff
  produce *about* an owner, not things an owner logs in to see.

**Revision.** This ADR originally accepted three panels (staff, owner, client) with four layers of
isolation. That was built in Phase 1 and removed once the business confirmed the system is
office-only. The four-layer isolation model in [`02-filament-panels.md`](02-filament-panels.md) went
with it. If a portal is ever reintroduced, that removed design is the starting point, not a fresh
one — and the argument in the original rejection still holds: the real risk is an unscoped query,
not a mis-authenticated user.

---

## ADR-008 — `cash_register_entries` is a view, not a table

**Status.** Accepted — a deliberate deviation from the requested table list.

**Context.** The brief lists `cash_register_entries` as a table, and separately requires cash balance
to be derived from the ledger.

**Decision.** A Postgres view over `transactions`, filtered to accounts with `is_cash_equivalent`,
projecting each side as a signed in/out row. Mapped to a read-only Eloquent model so Filament can list
and filter it normally.

**Consequences.**
- The two requirements are both satisfied: the register has a natural table to display, and there is
  still exactly one source of truth for cash.
- A real table would be a second place cash could be recorded, and the two would eventually disagree —
  usually discovered during a cash count, at the worst possible moment.
- Cost: the view is read-only, so register adjustments (opening float, banking, over/short) must be
  posted as proper transactions. That is the correct behaviour anyway.

---

## ADR-009 — Spatie Media Library for all files; no `*_path` columns

**Status.** Accepted.

**Context.** Car photos, document scans, contract PDFs, payment proofs, damage photos.

**Decision.** Media Library collections everywhere, on a **private disk**, served through a
policy-checked controller issuing short-lived signed URLs. The single exception is the generated
contract PDF, which keeps `pdf_disk`/`pdf_path` alongside its `document_hash` because the exact bytes
are legally significant.

**Consequences.**
- Conversions, ordering and disk abstraction come free; no `car_photos` table.
- A leaked customer ID scan URL expires instead of being permanently public — which `Storage::url()` on
  a public disk would guarantee.
- Cost: file access goes through the app rather than the web server. Acceptable at this scale.

---

## ADR-010 — Revenue is recognised at contract activation (pickup)

**Status.** Accepted, **flagged for confirmation with the business's accountant**.

**Context.** Revenue could be recognised at booking confirmation, at pickup, day-by-day across the
rental, or at return.

**Decision.** At contract activation, for the full contracted amount. Closeout adjustments (late fees,
excess km, fuel, cleaning) post additionally at return.

**Consequences.**
- Confirmed-but-never-collected bookings never create revenue.
- A three-month rental is not invisible for three months.
- Distortion: a long rental starting on the 28th books all its revenue into that month. Acceptable for
  typical rental durations; if long-term leasing becomes a real product line, add a scheduled job
  posting daily slices.
- **Change this before Phase 4, not after.** Changing recognition after go-live means restating
  history, which ADR-003 makes deliberately hard.

---

## ADR-011 — Fine liability is suggested, never assigned automatically

**Status.** Accepted.

**Context.** REQ-14 needs to know whether the customer or the company is responsible for a violation.
The system can match `violation_at` against the contract active for that car at that moment.

**Decision.** `FineLiabilityService` proposes liability with the matched contract and a confidence
signal. A human confirms, and the confirmation is recorded with who and when.

**Consequences.**
- Charging a customer for someone else's offence is a dispute the company loses, plus a customer lost.
- Handover boundaries are genuinely ambiguous — a violation at 09:58 on a car returned at 10:00 needs
  judgement.
- Cost: a manual step on every fine. Correct, given the money and the relationship at stake.

---

## ADR-012 — Alert deduplication is a requirement, not an optimisation

**Status.** Accepted.

**Context.** REQ-17 wants alerts before insurance expiry, maintenance due dates and payment due dates.
The naive implementation checks daily and sends on every match.

**Decision.** `NotificationService` checks `notification_logs` for the same
`(template_key, related_type, related_id, channel)` within the rule's `repeat_every_days` before
sending. Lead times and repeat intervals are configurable per rule in `alert_rules`.

**Consequences.**
- An insurance policy expiring in 30 days produces a handful of alerts, not thirty.
- Cost: an index on `notification_logs` and a check before every send.
- Rationale: a system that cries wolf daily gets muted, and then the one alert that mattered is missed
  too. Alert fatigue does not degrade the feature gracefully — it destroys it.

---

## ADR-013 — Services orchestrate; models and Filament resources stay thin

**Status.** Accepted.

**Context.** The default Filament path puts business logic in resource classes and model observers.

**Decision.** The layering in [`03-service-layer.md`](03-service-layer.md): models hold relationships
and casts only; Actions are single use cases; Services own orchestration and the transaction boundary;
Filament resources define forms and tables and call one Action or Service per button.

**Consequences.**
- The same operation behaves identically from the admin panel, the client portal, an artisan command
  and a future API — because there is one implementation.
- Testable without booting Filament.
- Cost: more classes, and more indirection than a small team may be used to. Justified by the number of
  money-touching paths.

---

## ADR-014 — Derived caches must be labelled, rebuildable and never authoritative

**Status.** Accepted.

**Context.** Multi-year dashboards may eventually scan a large ledger. The temptation is a rollup
table — which looks exactly like the stored totals the core constraint forbids.

**Decision.** Two caches are permitted, both explicitly labelled:
`ledger_daily_balances` (per account per day) and the `cars.*_expiry_date` mirror columns. Both are
rebuildable from source by an artisan command; truncating either loses no information; **no write
decision may read them as authoritative**. Neither is built speculatively — add them only when
measurement shows they are needed.

**Consequences.**
- The performance escape hatch exists without eroding the invariant.
- The test for whether a new denormalisation is acceptable: *can it be truncated and rebuilt with no
  information loss?* If not, it is a second source of truth and it is banned.

---

## Open questions for the user

Collected here rather than silently defaulted. None block starting Phase 0.

1. **Revenue recognition (ADR-010)** — confirm "at pickup, full amount" with the accountant before
   Phase 4. Cheap now, expensive later.
2. **Owner disclosure level** — how much does an owner on a `fixed_monthly` agreement see? The design
   currently shows their car's gross rental revenue and rental days, but not company margin. Confirm
   before Phase 10.
3. **TVA / VAT treatment** — account 2400 and the tax report exist, but Algerian rental VAT rules need
   confirming before Phase 9.
4. **Depreciation on company-owned cars (E48)** — include it, so company-owned and third-party cars are
   comparable in profitability reports? Recommended, but it is a business call.
5. **Long-term leasing** — if monthly leases become a product, revenue recognition and the booking
   calendar both need rethinking. Worth knowing now.
6. **WhatsApp provider** — WhatsApp Cloud API (official, template pre-approval required) or a
   third-party gateway? Affects Phase 5 contract delivery.
7. **Number of branches expected** — if it is genuinely one forever, Phase 10's multi-branch work can be
   dropped; the columns from ADR-004 cost nothing either way.
