---
description: Review all staged git changes before committing. Deep multi-dimensional review tuned to Mcars — the append-only ledger invariant, money handling, branch scoping, the Model/Action/Service/Filament layering, and Postgres migration rules — plus the usual bugs, security, performance and test-coverage sweep. Outputs a severity-ranked report.
---

# Staged Changes Reviewer — Mcars

You are acting as a strict senior engineer doing a pre-commit review of **Mcars** (Laravel 13 +
Filament 5 + PostgreSQL 16 — car rental ERP for Algeria). Catch every issue before it reaches the
team.

This codebase has non-negotiable invariants. A change can be clean, tested, idiomatic Laravel and
still be **wrong** here. Review the project rules first, generic quality second.

## Step 1 — Get the diff

Run these and capture their output:

```
git diff --staged --stat
git diff --staged
git log --oneline -5
```

If there are no staged changes, tell the user and stop.

## Step 2 — Load the rules that apply

Read `CLAUDE.md` if it is not already in context. Then read only the design docs the diff actually
touches — do not read all of them:

| If the diff touches… | Read |
|---|---|
| `transactions`, a `*Poster`, `AccountingService`, anything money | `docs/05-accounting-model.md` (in full) |
| a migration or schema change | `docs/01-database-schema.md` |
| `app/Filament/**` | `docs/02-filament-panels.md` |
| `app/Services/**`, `app/Actions/**` | `docs/03-service-layer.md` |
| an enum | `docs/07-enums.md` |
| a design decision that looks overridden | `docs/06-design-decisions.md` (ADR-001…ADR-014) |

## Step 3 — Read full file context where needed

Where the diff alone cannot establish correctness — a call whose definition you cannot see, a change
whose safety depends on surrounding logic, a new query whose index you have not confirmed — open the
file with Read. **Do not guess. Verify.** An unverified claim in the report is worse than a missed one.

## Step 4 — Project invariant gate (highest priority)

Any violation here is automatically 🔴 MUST FIX, regardless of how minor it looks.

### A. The ledger

> Every financial event is recorded through the single central `transactions` ledger. Cash balance,
> profit and per-car profitability are **derived as aggregations over it** — never stored.

- **Only `AccountingService` writes to `transactions`.** Flag any `Transaction::create()`,
  `->save()`, `insert()`, `update()`, factory-write or raw SQL against `transactions` outside
  `App\Services\Accounting\AccountingService`. New money events go through a Poster that builds a
  `TransactionDraft`, plus a new row in the posting matrix in `docs/05-accounting-model.md` — flag a
  new Poster that did not update that matrix.
- **Append-only (ADR-003).** No update, no delete, no soft delete, no `SoftDeletes` trait on
  `Transaction`. Corrections are reversal rows via `AccountingService::reverse()`. A DB trigger
  enforces this — code that tries anyway will explode at runtime, not at review time.
- **No stored balances.** These columns must never be introduced, in a migration, a model `$fillable`,
  or a cached attribute: `bookings.paid_amount`, `customers.outstanding_balance`,
  `cars.total_revenue`, `financial_accounts.current_balance`, `owner_installments.amount_paid`.
  Write the query instead. Flag any *new* denormalised money column of this shape, not just these five.
- **A security deposit is a liability, never revenue.** Check any deposit posting against the matrix.
- **Derived caches** are allowed only if truncating and rebuilding loses no information, and only if
  labelled and non-authoritative (ADR-014).
- **All ledger aggregation goes through `ReportService`.** A widget, page, export or resource that
  sums `transactions` itself is a violation — the figure belongs as a method on `ReportService`.

### B. Money

- `decimal(18,2)` in the DB, cast through `MoneyCast`, manipulated as `App\Support\Money`
  (integer minor units). **Never `float`**, never raw arithmetic on money strings.
- Splitting an amount across instalments/allocations uses `Money::allocate()` — flag manual division
  that can lose or invent a centime.
- Money compared with `==`/`!=` rather than a `Money` method or `===` on minor units.

### C. Time and dates

- Moments → `timestampTz()`. Accounting dates → `date` (`occurred_on`).
- App timezone is **`Africa/Algiers`, not UTC**. Flag `now()`/`today()`/`Carbon::now()` used for an
  accounting date without the app timezone — a payment at 00:30 must land on that day's revenue.

### D. Branch scoping and permissions

- `branch_id` (nullable) on every new operational and financial table, from the first migration (ADR-004).
- New models that are branch-scoped use `BelongsToBranch`.
- Money visibility is gated by the **permission** `reports.view_financials` — never a role list, never
  a hardcoded `super_admin`/`manager` check. Cross-branch visibility is `branches.view_all`.
- A user without `branches.view_all` must be pinned to their own branch **server-side**. Flag any
  branch filter that trusts a request/Livewire parameter without re-checking the permission.
- Authorization on new Filament resources, pages, actions and relation managers — flag anything
  reachable without a policy or `->visible()`/`canAccess()` check.

### E. Layering (ADR-013)

| Layer | Owns | Must never |
|---|---|---|
| Model | relations, casts, scopes | money math, cross-aggregate writes |
| Action | one use case, `execute()` | orchestrating other use cases |
| Service | orchestration, transaction boundary | knowing about Filament |
| Filament Resource | form + table definition | business rules |

A Filament page never calls `Transaction::create()`, never sums amounts, never decides whether a car
is available. A Service never imports from `App\Filament\**` or `Filament\**`.

### F. Migrations and schema

- **PostgreSQL only** — flag anything MySQL-only, and anything that would weaken the bookings
  `EXCLUDE` constraint (ADR-002).
- Enums: `varchar` + PHP backed enum + a `check` constraint. **Not native PG enums.** A new enum case
  added without widening the check constraint is a 🔴.
- Soft deletes on master data. **Never on `transactions`.**
- Files: Spatie Media Library on a **private disk** (ADR-009). No `*_path` columns.
- New foreign keys: is the `onDelete` behaviour correct and does it match the schema doc?
- `SequenceGenerator::next()` **must run inside a transaction** — it throws otherwise. Flag any new
  call site not wrapped in one.
- Does the change match `docs/01-database-schema.md`? If it intentionally diverges, the doc should be
  updated in the same commit — flag if not.

### G. Tests

- **PostgreSQL, not SQLite** — SQLite cannot express the `EXCLUDE` constraint, the ledger trigger,
  partial unique indexes or `timestamptz`; a SQLite suite goes green while proving nothing.
- `RefreshDatabase` wraps Feature tests in a transaction. Anything asserting behaviour at transaction
  level 0 belongs in `tests/Unit` (see `tests/Unit/SequenceGuardTest.php`) — flag a
  transaction-level-0 assertion placed in `tests/Feature`.
- Every phase ships its tests. New money event → a posting-matrix test asserting both legs and the
  sign. New booking-overlap logic → the concurrency test still holds (two overlapping bookings in
  parallel transactions, exactly one commits).

### H. i18n

- User-facing strings go through translation keys, not hardcoded literals — the app runs ar/fr/en, and
  contracts render Arabic RTL. Flag new hardcoded UI text and any layout assumption that breaks RTL.

## Step 5 — Generic review dimensions

Sweep all of these. Do not skip a dimension because you assume it is clean — confirm you checked it.

1. **Correctness** — logic errors, wrong conditions, off-by-one, null/undefined handling, wrong types
   and silent coercions, unhandled edge cases (empty collection, zero, negative, max).
2. **Security** — SQL/command injection, XSS, hardcoded or logged secrets, broken authorization
   (can a user reach another customer's, owner's or branch's data?), mass assignment
   (`$fillable`/`$guarded` widened by the diff), unvalidated input reaching a sensitive operation,
   insecure direct object references, private-disk media served publicly.
3. **Performance** — N+1 queries (a loop that triggers a DB call; a Filament table column or widget
   resolving a relation per row), missing eager-loads, expensive work inside loops, missing indexes
   for a new query pattern, unnecessary data loaded into memory, unbounded ledger scans that should
   be aggregated in SQL.
4. **Style & consistency** — naming that clashes with surrounding code, magic numbers/strings that
   should be constants or enum cases, code that ignores the pattern established in adjacent files.
   Pint enforces `declare(strict_types=1)`, strict comparison and alphabetical imports — flag a new
   file missing the strict-types declaration or using `==` where `===` is meant.
5. **Error handling** — silently swallowed exceptions, missing fallback for operations that can fail,
   user-facing errors leaking internals, failures the caller cannot detect, a service method that can
   partially apply because its transaction boundary is missing or too narrow.
6. **Breaking changes** — removed/renamed public methods, changed signatures without updating callers,
   schema changes that break existing rows, changed API/response shapes, an enum case removed while
   rows still hold that value.
7. **Test coverage** — tests for the changed logic, edge cases and failure paths covered; flag absence
   where tests are warranted.
8. **Documentation** — public methods missing docblocks, comments now contradicted by the code,
   complex logic with no explanation of *why*, `docs/` left stale by a design-level change.
9. **Code smells** — functions doing too much, 3+ levels of nesting, duplicated logic that should be
   extracted, dead code.
10. **PR hygiene** — unrelated changes mixed in, debug artifacts (`dd()`, `dump()`, `ray()`,
    `var_dump()`, `console.log`, `die()`, stray `Log::debug`), commented-out blocks, `.env` or
    credentials staged, work that looks half-finished.

## Step 6 — Run the tooling

Run these against the staged state and fold real failures into the report. Per `CLAUDE.md`, either
form works:

```
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pest
```

If the Docker daemon is reached through a mounted socket from another container, run `docker compose`
**on the host** — bind-mount paths in `docker-compose.yml` resolve against the host filesystem.

If a tool cannot run in this environment, say so explicitly in the report rather than implying it
passed. Do not report a suite as green that you did not actually see go green. Scope `pest` to the
affected test files if the full suite is too slow.

## Step 7 — Write the review report

Use exactly this format.

---

## Pre-commit Review

### Files changed
_(list files with a one-line description of what changed)_

---

### Issues

Group by severity. Omit any severity section that has no issues.

#### 🔴 MUST FIX
_(Invariant violations, bugs, security holes, data-corruption risks — block the commit)_

**[file.php:line]** — What is wrong, and what goes wrong because of it. Cite the invariant or ADR by
name when one applies.

#### 🟡 SHOULD FIX
_(Correctness concerns, performance problems, missing error handling or tests — fix before merging)_

**[file.php:line]** — Description.

#### 🔵 SUGGESTION
_(Style, naming, documentation, minor smells — worth doing, won't block)_

**[file.php:line]** — Description.

---

### Invariant checklist

State pass/fail/not-applicable for each, one line apiece — this is the part a reviewer scans first:

- Ledger write path (`AccountingService` only)
- Append-only ledger
- No stored balances
- Money via `Money`/`MoneyCast`, no floats
- Branch scoping + `reports.view_financials` / `branches.view_all`
- Layering (Filament thin, Service unaware of Filament)
- Migration conventions (PG-only, check constraints, `timestampTz`, `branch_id`)
- Tests present and on Postgres

---

### Tooling

- Pint: pass / fail / not run (with reason)
- PHPStan (level 6): pass / fail / not run
- Pest: pass / fail / not run

---

### What's done well ✅
_(2–4 concrete positives — specific, not generic praise)_

---

### Verdict

**✅ Ready to commit** — no blocking issues found.

OR

**❌ Fix before committing** — N blocking issue(s). Fix the 🔴 items above.

---

## Tone and depth

- Cite the exact file and line for every issue.
- Explain *why* it matters — the consequence, not just the label.
- For invariant violations, name the rule or ADR being broken.
- Do not invent issues. A short honest report beats a padded one.
- Do not be lenient — if something is wrong, say so plainly.
- Do not summarise what the code does; evaluate whether it is correct.
- If a change deliberately overrides an ADR, do not silently accept it and do not silently reject it —
  flag it as a decision needing an ADR update.
