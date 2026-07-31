# 13 — Transaction (Accounting)

**Model:** `App\Models\Transaction` · **Slug:** `/admin/transactions` · **Status:** ✅ audited — fine

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
| index | ✅ | 10 columns, `defaultSort('id','desc')`, 5 filters, eager loading, no poll |
| create | ❌ | correct — only `AccountingService` writes to `transactions` |
| view | ✅ | infolist + a `reverse` action |
| edit | ❌ | correct — ADR-003 |
| row actions | ✅ | `ViewAction` only |
| header / toolbar actions | ❌ | none — correct |
| relation managers | ❌ | none |
| `canAccess()` | ✅ | `reports.view_financials` (`TransactionResource.php:37`) |
The invariant is respected end to end: no create page, no edit page, no delete action, no
bulk actions, no soft deletes. `defaultSort` is `id desc`, the true posting order for an
append-only table. The only mutation offered is a reversal, on the view page
(`ViewTransaction.php:49-77`): it delegates to `AccountingService::reverse()`, requires a
reason, refuses to reverse a reversal — matching the service's own guard at
`AccountingService.php:104` — and is gated on the now-seeded `reverse_transaction`
permission.

## Should be

### Index

Implemented, per the original spec:

- **Date range** on `occurred_on` (a `date` — the accounting date, not `posted_at`),
  built as a `Filter` with two `DatePicker`s; open-ended from / to both work.
- `SelectFilter` on `type` (`TransactionType::options()`).
- **Either-leg account filter** — a `SelectFilter` over postable chart accounts that
  matches rows where the account is the debit *or* the credit leg.
- `TernaryFilter` on `is_reversal`.
- Branch filter (`->relationship('branch', 'name')`), visible only with
  `branches.view_all`.

Column fixes: `is_reversal` renders through an `IconColumn`; `description` is truncated
with a full-value `->tooltip()`.

`->poll('30s')` was dropped — the ledger is not a live queue, and polling the largest
table in the schema twice a minute bought nothing.

The index eager-loads `debitAccount`, `creditAccount` and `createdBy`
(`->modifyQueryUsing()` in `TransactionResource.php:109`).

### Create

Must never exist. Only `AccountingService` writes to `transactions`; a new money event is a
new Poster plus a row in the posting matrix in `../05-accounting-model.md`. The dead
`form()` method was deleted.

### View

Four sections:

- **Transaction** — reference, type, amount (DZD), `occurred_on` vs `posted_at`, who
  posted it, reversal icon, description.
- **Posting** — both legs as `code · name`, payment method.
- **Source Document** — the `source_type` / `source_id` pair rendered as a clickable
  `Expense #42`-style label (10 source types mapped to their resources; expense links to
  `view`, everything else to `edit` — the only view page among them), plus the reversal
  relationship in **both** directions.
- **Dimensions** — branch, car, customer, car owner, booking, contract, cash session and
  expense category, each a link when set.

One modelling note: the schema's `reversed_by_transaction_id` is **never written** —
`AccountingService::reverse()` only sets `reverses_transaction_id` on the new row. The
"this row was reversed" side is therefore a derived `reversal()` hasOne on
`reverses_transaction_id` (`Transaction.php:163-167`), which makes a reversed original
point at its reversal. A reversed transaction that looks identical to a live one is how
figures get double-counted by eye, so the view page now always says.

### Edit

Must never exist (ADR-003). Corrections are reversal rows. A database trigger enforces
this, so an attempt fails at runtime rather than at review time — and the UI offers no
path to it.

### Relations

None, and that is deliberate — a transaction is a leaf. Its dimensions (`booking_id`,
`car_id`, `customer_id`, `car_owner_id`, `expense_category_id`, `cash_session_id`) are
nullable pointers *outward*, so they live in the view page as links, not as relation
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
| `reverse` | header (view page) | `! is_reversal` | `reverse_transaction` — seeded | `AccountingService::reverse()` | requires a reason |
| _(none)_ | create / edit / delete / bulk | — | — | — | **deliberately absent** (ADR-003) — keep it that way |

## Gaps and risks

1. **✅ RESOLVED — the reverse action was unreachable by everyone, including super_admin.**
   Fixed: `reverse_transaction` is now seeded to super_admin and accountant, and
   `tests/Feature/PrivilegeEscalationTest.php` asserts the accountant can see it. Kept here
   because the reasoning explains why an unseeded permission denies everyone, which applies
   to every new permission proposed in this directory.
2. **✅ RESOLVED — no filters on the largest table in the schema.** Five filters now, see
   Index; `tests/Feature/TransactionResourceTest.php` covers the date range, the either-leg
   account filter and the read-only surface.
3. **✅ RESOLVED — N+1 on the index.** `debitAccount`, `creditAccount` and `createdBy` are
   eager-loaded.
4. **✅ RESOLVED — `->poll('30s')`** dropped.
5. **✅ RESOLVED — `is_reversal` badge rendered `1`/`0`.** Now an `IconColumn`.
6. **✅ RESOLVED — `description` truncated with no tooltip.** Tooltip added.
7. **✅ RESOLVED — dead `form()` method.** Deleted.

## Checklist

- [x] Seed `reverse_transaction` (super_admin + accountant), covered by
      `tests/Feature/PrivilegeEscalationTest.php`
- [x] Add the `occurred_on` range, type, either-leg account, `is_reversal` and branch filters
- [x] Eager-load `debitAccount`, `creditAccount`, `createdBy`
- [x] Drop `->poll('30s')`
- [x] Render `is_reversal` as an icon; add a tooltip to `description`
- [x] Link the view page to the source document, and show reversal relationships
      in both directions
- [x] Delete the empty `form()`
- [x] Assert in a test that this resource exposes no create, edit, delete or bulk action —
      `tests/Feature/TransactionResourceTest.php`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/TransactionResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/PrivilegeEscalationTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`SchemaConventionsTest` asserts `transactions` has no soft-delete column and that no banned
stored-balance column exists — both stay green.

By hand: open a transaction as an accountant and confirm the reverse action is visible;
reverse it, and confirm a **new** row appears rather than the original changing. Confirm the
original is still present and now links to its reversal, and the reversal links back. Then
open the same page as a receptionist and confirm the whole resource is refused, not merely
the action.
