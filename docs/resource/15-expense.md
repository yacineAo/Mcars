# 15 — Expense (Accounting)

**Model:** `App\Models\Expense` · **Slug:** `/admin/expenses` · **Status:** ✅ audited — fine

Closes **REQ-10**. Read [`../05-accounting-model.md`](../05-accounting-model.md) — the
expense postings are in the matrix.

## What it is for

Everything the business spends: fuel, parts, salaries, rent, registration. An office clerk
records one, a manager approves it, and paying it posts to the ledger. It is the main
counterweight to revenue in the P&L, so a mis-recorded expense shows up directly in profit.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | status (default pending queue), date range, category, vendor, car, branch filters |
| create | ✅ | `car_id` conditional + required on `is_car_related` categories |
| view | ✅ | header actions delegate to `ExpenseService`; postings relation group |
| edit | ✅ | frozen once `Paid`, except notes and receipts |
| row actions | ✅ | `approve`, `pay`, View, Edit — `->recordActions([...])`, no bulk delete |
| header / toolbar actions | ✅ | submit, approve, reject, pay — each gated by permission and status |
| relation managers | ✅ | read-only `transactions` (posting + reversals), gated on `reports.view_financials` |
| `canAccess()` | ✅ | `expenses.record` \| `expenses.approve` \| `expenses.pay` |

Confirmed correct: **no tax anywhere.** The form comments that the total mirrors the amount,
consistent with the scope decision that this system charges no tax — `expenses.tax_amount`,
posting E03, `TransactionType::Tax` and account 2400 do not exist. Payment posts through
`ExpenseService::pay()` → `ExpensePoster` → `AccountingService::post()` rather than writing to
`transactions` itself.

## Design decisions

### Permissions — three separate grants

- `expenses.record` — SuperAdmin, Manager, Accountant, Receptionist. Broad on purpose: the
  counter clerk who watched the fuel go in is the one who records it. Recording alone grants
  nothing else — the clerk can neither approve nor pay.
- `expenses.approve` — SuperAdmin, Manager. The pending queue is a manager's worklist, and
  only the manager can sign an entry off. Not granted to the accountant, so recording is
  never enough to push an entry through to the ledger — an accountant who recorded an
  expense must still have it approved.
- `expenses.pay` — SuperAdmin, Manager, Accountant. The accountant answers for the books,
  so they may pay entries others recorded — including, if they recorded them, their own:
  the approval gate is the control that keeps a recorder from moving money through the
  ledger unchecked, not the payment one.

Anyone holding one of the three may read expenses; a supervisor holds none and is denied.

### Service owns every transition

`App\Services\ExpenseService` is the only writer of statuses:

- `submitForApproval($expense, $by)` — `Draft` only.
- `approve($expense, $by)` — `Draft` or `PendingApproval`; writes `approved_by_id`/`approved_at`.
- `reject($expense, $reason, $by)` — `Draft` or `PendingApproval`; records the reason.
- `pay($expense, $method, $account, $by)` — `Approved` only; inside a `DB::transaction` with
  `lockForUpdate()` on the row, posts E39 via the Poster, then records `financial_account_id`,
  `paid_at`, `transaction_id` and status `Paid`.

Status violations throw `RuntimeException`; the guards are invariants, not button visibility.
The row lock makes a second pay in a stale tab a refused transition, not a duplicate posting.

### Delete

`DeleteBulkAction` removed. Single delete is allowed only while `transaction_id === null` — a
posted expense is ledger history, and the correct correction is a reversal.

### Freeze once paid

Every money and status field is disabled when `status = Paid`; only `notes` and receipts
(Media Library, private disk) stay editable. A wrong amount is corrected by reversing the
posting, so `transactions` and the expense row can never disagree.

### Postings relation manager

One expense produces one posting (`transaction_id` is a single column), but a reversal adds a
second row referencing it — the read-only `transactions` table shows the posting *and*
anything that reverses it, gated on `reports.view_financials`, so a reversed expense cannot
look paid and correct.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ExpenseResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
```

`ExpenseResourceTest` covers the access matrix (receptionist records but neither approves nor
pays; accountant pays but does not approve; manager approves and pays; supervisor denied),
the full service lifecycle with the E39 legs asserted, the double-pay refusal, the reject
reason, `car_id` conditional validation, the freeze once paid, the delete guard, the pending
queue default, branch pinning, and the postings table showing the posting and its reversal.
