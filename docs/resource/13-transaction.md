# 13 — Transaction (Accounting)

**Model:** `App\Models\Transaction` · **Slug:** `/admin/transactions` · **Status:** 🟡 partial

Closes **REQ-08**, **REQ-09**. Read
[`../05-accounting-model.md`](../05-accounting-model.md) in full before changing anything
here, and [`../06-design-decisions.md`](../06-design-decisions.md) ADR-003.

## What it is for

The ledger itself — every financial event in the business, one row per posting. An
accountant opens it to trace where a figure came from; nobody else should open it at all.
It is the only screen in the panel whose data is **append-only**, which shapes every
decision below.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 10 columns, `defaultSort('id','desc')`, `->poll('30s')`, `->filters([])` **empty** |
| create | ❌ | correct — only `AccountingService` writes to `transactions` |
| view | ✅ | infolist + a `reverse` action |
| edit | ❌ | correct — ADR-003 |
| row actions | ✅ | `ViewAction` only |
| header / toolbar actions | ❌ | none — correct |
| relation managers | ❌ | none |
| `canAccess()` | ✅ | `reports.view_financials` (`TransactionResource.php:30`) |

**This resource gets the invariant right, and that is worth stating plainly.** No create
page, no edit page, no delete action, no bulk actions, no soft deletes. `defaultSort` is
`id desc`, which is the true posting order for an append-only table. The only mutation
offered is a reversal, on the view page (`ViewTransaction.php:22-54`): it delegates to
`AccountingService::reverse()`, requires a reason, and refuses to reverse a reversal —
matching the service's own guard at `AccountingService.php:104`.

The problem is that nobody can reach it. See gap 1.

## Should be

### Index

The empty `->filters([])` is the main defect. This table grows by several rows per booking
and never shrinks; within a year it is the largest table in the database, and there is
currently no way to narrow it. Add:

- **Date range** on `occurred_on` — the filter an accountant reaches for first. Note
  `occurred_on` is a `date` (the accounting date), not a timestamp; filter on it, not on
  `posted_at`.
- `SelectFilter` on `type` (`TransactionType`).
- Account filter matching **either** leg — a user looking for account 5060 wants rows where
  it is debited *or* credited, so this cannot be a plain relation filter on one column.
- `TernaryFilter` on `is_reversal`.
- Branch filter, visible only with `branches.view_all`.

Two column fixes: `is_reversal` is a boolean rendered through `TextColumn::make(...)->badge()`,
which shows a raw `1`/`0` rather than a word — make it an `IconColumn` or format the state.
And `description` is truncated at 40 characters with no tooltip, so the one column that
explains the row is the one you cannot read; add `->tooltip()`.

Reconsider `->poll('30s')`. Every open tab re-runs a query over the largest table in the
schema twice a minute. The ledger is not a live queue — an accountant tracing a figure does
not need it to move under them. Drop the poll, or raise it substantially.

### Create

Must never exist. Only `AccountingService` writes to `transactions`; a new money event is a
new Poster plus a row in the posting matrix in `../05-accounting-model.md`.

`form()` currently returns a bare `$schema` with no components — dead code, since no page
uses it. Harmless, but it invites someone to fill it in. Delete it.

### View

Keep. It should answer "what is this row, and what caused it": reference, type, amount,
both legs with account code and name, `occurred_on` vs `posted_at`, who posted it, the
description, and the source document (`source_type` / `source_id`) as a link to the booking,
payment or expense that produced it — that last part is what makes the screen worth opening
and is worth checking is present.

If the row is a reversal, show what it reverses; if it has been reversed, say so and link to
the reversing row. A reversed transaction that looks identical to a live one is how figures
get double-counted by eye.

### Edit

Must never exist (ADR-003). Corrections are reversal rows. A database trigger enforces this,
so an attempt fails at runtime rather than at review time — but the UI should never offer it.

### Relations

None, and that is deliberate — a transaction is a leaf. Its dimensions (`booking_id`,
`car_id`, `customer_id`, `car_owner_id`, `expense_category_id`, `cash_session_id`) are
nullable pointers *outward*, so they belong in the view page as links, not as relation
managers.

The reverse direction is where relations belong: a cash session's, an account's or an
expense's postings shown read-only on **their** view pages. See
[`14-cash-session.md`](14-cash-session.md) and `15-expense.md`. Any such table must be
strictly read-only — no create, no edit, no delete, no bulk actions — and gated on
`reports.view_financials`.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `ViewAction` | row | always | `reports.view_financials` | — | the only row action — correct |
| `reverse` | header (view page) | `! is_reversal` | `reverse_transaction` — **now seeded** | `AccountingService::reverse()` | gap 1 resolved |
| _(none)_ | create / edit / delete / bulk | — | — | — | **deliberately absent** (ADR-003) — keep it that way |

## Gaps and risks

1. **✅ RESOLVED — the reverse action was unreachable by everyone, including super_admin.**
   Fixed: `reverse_transaction` is now seeded to super_admin and accountant. Kept here because
   the reasoning explains why an unseeded permission denies everyone, which applies to every new
   permission proposed in this directory.
   Original finding:
   `ViewTransaction.php:54` gates it on `can('reverse_transaction')`. That permission is
   **never created or granted** — it appears in that one line and nowhere else;
   `RolePermissionSeeder` defines only `branches.view_all`,
   `reports.view_financials`, `alerts.manage` and `alerts.view_logs`. And Shield's
   `super_admin` has `'define_via_gate' => false` with no `Gate::before` anywhere in
   `app/`, so the role carries only explicitly assigned permissions.
   Verified directly: for `admin@mcars.local` (super_admin),
   `can('reverse_transaction')` returns **false** while `can('reports.view_financials')`
   returns true.
   **Consequence:** the ledger is append-only and its only sanctioned correction path is
   invisible in the UI. A mis-posted transaction cannot be corrected by anyone without a
   deploy or a tinker session. Either seed `reverse_transaction` (super_admin and
   accountant, at minimum) or change the gate to a permission that exists — and add a test
   asserting an accountant can see the action, so it cannot silently regress.
2. **🟡 No filters on the largest table in the schema.** See Index.
3. **🟡 N+1 on the index.** `debitAccount.name`, `creditAccount.name` and `createdBy.name`
   are three relation lookups per row with no eager loading in the resource or
   `ListTransactions`. At 25 rows that is 75 extra queries, on a table whose row count only
   goes up.
4. **🟡 `->poll('30s')`** on the biggest table, for every viewer. See Index.
5. **🔵 `is_reversal` badge renders `1`/`0`.**
6. **🔵 `description` truncated with no tooltip.**
7. **🔵 Dead `form()` method.**

## Checklist

- [x] Seed `reverse_transaction` (super_admin + accountant), covered by
      `tests/Feature/PrivilegeEscalationTest.php`
- [ ] Add the `occurred_on` range, type, either-leg account, `is_reversal` and branch filters
- [ ] Eager-load `debitAccount`, `creditAccount`, `createdBy`
- [ ] Drop or lengthen `->poll('30s')`
- [ ] Render `is_reversal` as an icon; add a tooltip to `description`
- [ ] Confirm the view page links to the source document, and shows reversal relationships
      in both directions
- [ ] Delete the empty `form()`
- [ ] Assert in a test that this resource exposes no create, edit, delete or bulk action


> **Partly done.** The items ticked above were implemented and covered by
> `tests/Feature/PrivilegeEscalationTest.php`. The rest of the checklist is untouched.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
```

`SchemaConventionsTest` already asserts `transactions` has no soft-delete column and that
no banned stored-balance column exists — both must stay green.

By hand: open a transaction as an accountant and confirm the reverse action is now visible;
reverse it, and confirm a **new** row appears rather than the original changing. Confirm the
original is still present and marked as reversed. Then open the same page as a receptionist
and confirm the whole resource is refused, not merely the action.
