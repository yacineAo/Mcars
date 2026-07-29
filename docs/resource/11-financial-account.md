# 11 — FinancialAccount (Accounting)

**Model:** `App\Models\FinancialAccount` · **Slug:** `/admin/financial-accounts` · **Status:** 🔴 needs work

Closes **REQ-09**. See [`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

The real-world places money sits: the till, the CCP account, a bank account, BaridiMob. Each
one maps to a ledger account via `ledger_account_id`, which is what lets a payment know both
where the cash went and how to post it. Set up once, edited rarely.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | `->filters([])` empty |
| create | ✅ | name, type, account number, RIB, holder, currency, opening balance, allowed methods, default-for-cash, active |
| view | ❌ | see Relations |
| edit | ✅ | nothing frozen |
| row actions | ✅ | Edit, Delete — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | **absent** |

Confirmed good: the table has **no `current_balance` column**. Its columns are
`opening_balance`, `opened_on`, … — an opening figure, not a running one. That is exactly
right (CLAUDE.md bans `financial_accounts.current_balance`); any balance shown must be
derived through `CashRegisterService` / `ReportService`.

## Should be

### Index
Add the balance the screen is missing — **current balance, derived**, not stored. "How much
is in the till right now" is the only question this list is opened to answer, and it cannot
be answered here today. Source it from `CashRegisterService::cashOnHand()` or an equivalent
`ReportService` method, gated on `reports.view_financials`. If that means one query per row,
add a method that returns balances for all accounts in one query rather than accepting N+1.

Filters: `type`, `is_active`, and branch with `branches.view_all`.

### Create
Two rules that belong in a service, surfaced here as validation:

- **Only one account may be `is_default_for_cash`.** Nothing currently prevents a second,
  and `resolveBranchId`-style "pick the default" lookups will then be arbitrary.
- `ledger_account_id` should be restricted to accounts that are `is_postable` and
  cash-equivalent where the type implies it — pointing a till at a revenue account produces
  postings that balance but mean nothing.

`rib`, `account_number` and `holder_name` should show conditionally on `type`: a cash till
has none of them, a CCP account has all three.

### View
Worth adding, for the postings table below — "show me every movement through the CCP account
this month" currently has no home.

### Edit
Freeze `ledger_account_id` and `opening_balance` once the account has postings. Both feed the
derived balance; changing either retroactively rewrites every balance ever shown for that
account, without touching the append-only rows that produced it.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` (either leg) | **view** | **yes, strictly** | `reports.view_financials` | reference, date, type, amount, running total |
| `cashSessions` | **view** | yes | `reports.view_financials` | opened/closed, opened by, counted, variance |

Neither exists as a `hasMany` on the model today (the model has no has-many relations at
all), so both need adding — or building as query-backed tables. The cash-sessions table is
the more useful of the two for a manager: it is the till's history per account.

Strictly read-only, no bulk actions (ADR-003).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | **nothing** | — | freeze ledger account + opening balance once posted |
| `DeleteAction` | row | always | **nothing** | — | must refuse accounts with postings |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 2 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Any staff role can create, edit and bulk-delete the accounts that
   money is posted against, including the RIB and holder name of the company's bank accounts.
   This is bank detail — it should be `reports.view_financials` at minimum for read, and
   narrower for write.
2. **🔴 `DeleteBulkAction`.** Deleting an account that payments and cash sessions reference
   leaves those rows pointing at nothing, and the derived balance for it becomes
   unanswerable. Remove it; guard single delete on having postings.
3. **🟡 No current balance anywhere in the UI.** The invariant is correctly honoured in the
   schema, but the consequence — that someone must *write the query* — was never done, so
   the panel cannot tell you how much is in the till. See Index.
4. **🟡 Nothing stops two default-cash accounts.**
5. **🟡 No conditional fields by `type`** — a cash till currently prompts for a RIB.
6. **🟡 No filters.**
7. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Add `canAccess()`; decide the read/write split for bank details
- [ ] Remove `DeleteBulkAction`; guard single delete on postings
- [ ] Add a derived current-balance column, gated, with a batched query (no N+1)
- [ ] Enforce a single `is_default_for_cash` in a service
- [ ] Restrict `ledger_account_id` to sensible accounts
- [ ] Show `rib` / `account_number` / `holder_name` conditionally on `type`
- [ ] Add `type` / `is_active` / branch filters
- [ ] Add a view page with read-only, gated transactions and cash-sessions tables
- [ ] Freeze `ledger_account_id` and `opening_balance` once postings exist
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
```

`SchemaConventionsTest` asserts the banned stored-balance columns do not exist — it must stay
green, which means the new balance column is computed, never persisted.

By hand: take a cash payment, then confirm the balance shown for that account moves by the
same amount and matches the cash-session audit report at `/admin/reports`.
