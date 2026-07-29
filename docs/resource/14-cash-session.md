# 14 — CashSession (Accounting)

**Model:** `App\Models\CashSession` · **Slug:** `/admin/cash-sessions` · **Status:** 🔴 needs work

Closes **REQ-09** (cash register). See
[`../tasks/phase-04-ledger-cash-register.md`](../tasks/phase-04-ledger-cash-register.md)
and [`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

The till. A receptionist opens a session at the start of a shift with a float, takes cash
against it all day, then counts the drawer and closes it. The variance between what was
counted and what the ledger expects is the number the business cares about — it is how
missing cash is found the same day rather than at month end.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 8 columns, `defaultSort('id','desc')`, `->filters([])` **empty** |
| create | ✅ | 3 fields: account, opening float, notes |
| view | ❌ | absent |
| edit | ✅ | `EditCashSession` — also carries a close action and a `DeleteAction` |
| row actions | ✅ | `close_session`, View, Edit — but `ViewAction` has no view page to open |
| header / toolbar actions | 🟡 | `CreateAction`; `->bulkActions([])` — correctly empty |
| relation managers | ❌ | none, though `CashSession hasMany transactions` |
| `canAccess()` | ❌ | absent |

The close action is correctly built: it delegates to
`CashRegisterService::closeSession()` (`CashSessionResource.php:95-96`) and tells the user
the variance was posted to the ledger. Nothing about the reconciliation is computed in the
resource, which is right.

## Should be

### Index

This is a shift-level operational log, so the filters it lacks are the obvious ones:

- **Open sessions only** — a `TernaryFilter` or a status filter defaulting to open. A
  session left open overnight is the single thing a manager needs to spot here, and right
  now it is invisible among closed ones.
- `SelectFilter` on `status` (`CashSessionStatus`) — note `Disputed` exists as a state and
  currently has no way to be listed.
- Date range on `opened_at`.
- `SelectFilter` on `financial_account_id`, and on branch with `branches.view_all`.

Add the two columns the screen is missing, both of which are the point of the feature:
**expected** and **variance**. They must come from `CashRegisterService` /
`ReportService::cashSessionAudit()` — never be computed in the resource — and variance should
be coloured (short = danger, over = warning, exact = success), matching how
`ViewReport` renders the same figure for the cash-session audit report. Today a user sees
`opening_float` and `counted_amount` and is left to do the arithmetic in their head.

`closed_at` should render as "—" rather than blank when a session is open.

### Create

Three fields is right — opening a session is a small act. Two additions worth considering:
default `financial_account_id` to the account flagged `is_default_for_cash`, and refuse to
open a second session for the same account while one is still open. That second rule is a
business invariant and belongs in `CashRegisterService`, surfaced here as a validation
message, not as a form rule.

### Edit

**This page should probably not exist.** A cash session's fields are its identity: the
account, the float it opened with, when it opened, who opened it. Editing `opening_float`
after cash has moved against it silently changes the expected balance and therefore the
variance — the one figure the whole feature exists to produce. Reduce edit to `notes` only,
or remove the page and keep notes editable through the close action.

`EditCashSession` also carries a duplicate close action (`EditCashSession.php:47`) and a
`DeleteAction`. See gaps 1 and 2.

### View

**Add one**, and make it where a session is closed. It is the natural home for: the account
and float, opened/closed by and when, expected vs counted vs variance as three prominent
figures, closing notes, and the session's postings (see Relations). The row action
`ViewAction::make()` is already in the table pointing at a page that does not exist.

### Relations

`CashSession hasMany transactions`.

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` | **view** | **yes, strictly** | `reports.view_financials` | reference, type, debit, credit, amount, occurred on |

This is the strongest case for a view page in the Accounting group: "show me every movement
in this till today" is exactly what someone asks when a drawer is short, and there is
currently no way to answer it without filtering the whole ledger by hand — which
[`13-transaction.md`](13-transaction.md) notes is not currently possible either, because that
table has no filters.

Strictly read-only: no create, no edit, no delete, no bulk actions (ADR-003). Default the
table to `occurred_on` then `id` so it reads in posting order.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `close_session` | row | `status === Open` | **nothing** | `CashRegisterService::closeSession()` | correctly delegated |
| `close_session` | edit page | `status === Open` | **nothing** | same | **duplicate** — gap 5 |
| `ViewAction` | row | always | **nothing** | — | **no view page exists** — gap 3 |
| `EditAction` | row | always | **nothing** | — | reduce to notes — see Edit |
| `DeleteAction` | edit page | always | **nothing** | — | **restrict** — gap 1 |

## Gaps and risks

1. **🔴 `DeleteAction` on a closed cash session** (`EditCashSession.php:49`). Closing a
   session posts a variance to the append-only ledger. Deleting the session afterwards
   leaves that posting referencing a row that is gone, and `transactions.cash_session_id`
   pointing at nothing — a reconciliation that cannot be explained. Restrict deletion to
   sessions that are still open **and** have no transactions, or remove it entirely.
2. **🔴 No `canAccess()`.** Anyone can open, close and delete a till session. Opening and
   closing is a receptionist's job, so this is not simply `reports.view_financials` — it
   needs a deliberate split: operating the till versus seeing the variance. Decide it here
   and name the permission.
3. **🔴 `ViewAction` with no view page.** `CashSessionResource.php:106` registers a
   `ViewAction` while `getPages()` has no `view` entry. Filament falls back to a modal
   infolist, which for this resource is the wrong surface for the close workflow — and
   whether it renders at all should be verified.
4. **🟡 Expected and variance are absent from the index.** The feature's whole output is
   missing from its list screen. See Index.
5. **🟡 Duplicate close action** on both the table row and the edit page. Two entry points
   to a state transition is two places to keep the guard correct. Keep one — the view page,
   once it exists.
6. **🟡 No filters**, including no way to list open or disputed sessions. See Index.
7. **🟡 N+1**: `financialAccount.name` and `openedBy.name` per row, no eager loading.
8. **🟡 Deprecated `->actions([...])`.**
9. **🔵 PHPStan false positive, not a runtime bug.** PHPStan reports "strict comparison
   using === between string and CashSessionStatus" at `CashSessionResource.php:104` and
   `EditCashSession.php:47`. I verified the cast is present
   (`CashSession::casts()` → `'status' => CashSessionStatus::class`) and that at runtime
   `$session->status` is a real enum instance, so the comparison behaves correctly and the
   close action does appear. The cause is larastan reading the column type from the schema
   rather than from `casts()`. Fix it the same way `User::$locale` was fixed: add
   `@property CashSessionStatus $status` to the model docblock. Do **not** "fix" the
   comparison — it is correct.

## Checklist

- [ ] Add a view page: float, opened/closed by, expected vs counted vs variance, notes
- [ ] Add the `transactions` relation manager to it, strictly read-only and gated
- [ ] Add expected and variance columns to the index, sourced from `CashRegisterService`
- [ ] Add open/status/date/account filters; surface `Disputed`
- [ ] Restrict or remove `DeleteAction`
- [ ] Reduce the edit page to `notes`, or remove it
- [ ] Keep one close action, not two
- [ ] Add `canAccess()`; decide operate-the-till versus see-the-variance
- [ ] Verify or remove the `ViewAction` until a view page exists
- [ ] Eager-load `financialAccount` and `openedBy`
- [ ] Add `@property CashSessionStatus $status` to the model
- [ ] Prevent a second open session on the same account, in `CashRegisterService`
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CashSessionResource app/Models/CashSession.php
```

By hand: open a session, take a cash payment against it, then close it counting a
deliberately short amount. Confirm the variance posts to the ledger, that the figure shown
on screen equals the one in the cash-session audit report (`/admin/reports`), and that the
session's postings are listed on its view page. Then confirm a receptionist can open and
close a session but cannot see the variance if that is the split you chose.
