# 11 — FinancialAccount (Accounting)

**Model:** `App\Models\FinancialAccount` · **Slug:** `/admin/financial-accounts` · **Status:** 🟡 partial

Closes **REQ-09**. See [`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

The real-world places money sits: the till, the CCP account, a bank account, BaridiMob. Each
one maps to a ledger account via `ledger_account_id`, which is what lets a payment know both
where the cash went and how to post it. Set up once, edited rarely.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | filters: type, is_active, branch |
| create | ✅ | name, type, ledger account, account number, RIB, holder, currency, opening balance, allowed methods, default-for-cash, active |
| view | ✅ | account details, bank details (conditional), derived current balance gated on `reports.view_financials`, transactions + cash sessions relation managers |
| edit | ✅ | `ledger_account_id` and `opening_balance` frozen once postings exist |
| row actions | ✅ | View, Edit — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` only; bulk actions removed |
| relation managers | ✅ | Transactions (both legs, read-only, gated) + CashSessions (read-only, gated) on the view page |
| `canAccess()` | ✅ | gates on `reports.view_financials` |

Confirmed good: the table has **no `current_balance` column**. Its columns are
`opening_balance`, `opened_on`, … — an opening figure, not a running one. That is exactly
right (CLAUDE.md bans `financial_accounts.current_balance`); any balance shown must be
derived through `CashRegisterService` / `ReportService`. The index uses
`scopeWithCurrentBalance()` + `CashRegisterService::balancesBatch()` for N+1-safe display.

## What remains

1. **Enforce a single `is_default_for_cash` in a service.** Partially done — `CreateFinancialAccount`
   and `EditFinancialAccount` mutate form data to reset other defaults. Belongs in a dedicated
   action/service when the pattern is established elsewhere.
2. **Restrict `ledger_account_id` to cash-equivalent accounts.** Currently filtered to
   `is_postable` accounts only, which excludes revenue accounts but still allows pointing a
   till at a payable. Should also filter to `is_cash_equivalent` where the type implies it.

## Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` (either leg) | view | yes, strictly | `reports.view_financials` | occurred_on, description, type, amount, debit account, credit account |
| `cashSessions` | view | yes | `reports.view_financials` | opened_at, closed_at, opening_float, counted_amount, status, opened by |

Strictly read-only, no bulk actions (ADR-003).

## Actions

| Action | Placement | Visible when | Guarded by | Notes |
|---|---|---|---|---|
| `ViewAction` | row | always | `reports.view_financials` on resource | — |
| `EditAction` | row | always | `reports.view_financials` on resource | `ledger_account_id` + `opening_balance` frozen once posted |

## Checklist

- [ ] Enforce a single `is_default_for_cash` in a service
- [ ] Restrict `ledger_account_id` to sensible accounts (cash-equivalent)

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/FinancialAccountTest.php
```

`SchemaConventionsTest` asserts the banned stored-balance columns do not exist — it must stay
green, which means the new balance column is computed, never persisted.

By hand: take a cash payment, then confirm the balance shown for that account moves by the
same amount and matches the cash-session audit report at `/admin/reports`.
