# 15 — Expense (Accounting)

**Model:** `App\Models\Expense` · **Slug:** `/admin/expenses` · **Status:** 🔴 needs work

Closes **REQ-10**. Read [`../05-accounting-model.md`](../05-accounting-model.md) — the
expense postings are in the matrix.

## What it is for

Everything the business spends: fuel, parts, salaries, rent, registration. An office clerk
records one, a manager approves it, and paying it posts to the ledger. It is the main
counterweight to revenue in the P&L, so a mis-recorded expense shows up directly in profit.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | `->filters([])` **empty** |
| create | ✅ | includes the comment "No tax is charged, so the total simply mirrors the amount" (line 79) |
| view | ✅ | present |
| edit | ✅ | nothing frozen — editable after posting |
| row actions | ✅ | `approve`, `pay`, View, Edit — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | **absent** |

Confirmed correct: **no tax anywhere.** The form comments that the total mirrors the amount
(`ExpenseResource.php:79`), consistent with the scope decision that this system charges no
tax — `expenses.tax_amount`, posting E03, `TransactionType::Tax` and account 2400 do not
exist. Also correct: the `pay` action posts through `ExpensePoster` → `AccountingService::post()`
rather than writing to `transactions` itself.

## Should be

### Index
The empty filter list is the biggest usability gap. An expense list is read by period and by
category, and neither is filterable. Add:

- `SelectFilter` on `status` (`ExpenseStatus`) — with **pending approval** as the default view,
  since that is the queue a manager works.
- Date range on `incurred_on`.
- `SelectFilter` on `expense_category_id`, on `vendor_id`, and on `car_id`.
- Branch filter with `branches.view_all`.

Columns should show category, vendor, amount, `incurred_on`, status badge, and the car when
`is_car_related`. Total amount is money — the whole resource arguably belongs behind
`reports.view_financials` (see gap 1).

### Create
Reasonable. `car_id` should appear only when the chosen category is `is_car_related`, and be
required when it is — an expense that should attribute to a car but has no `car_id` silently
drops out of per-car profitability, which is the failure this screen can cause that nobody
notices.

### View
Keep. Add the ledger postings (see Relations) so an accountant can see both legs of what this
expense produced without leaving the record.

### Edit
**Freeze once paid.** Today the edit form remains fully open after the `pay` action has posted
to the append-only ledger. Editing `amount` afterwards leaves `transactions` holding the old
figure while the expense row shows the new one — the two disagree permanently, and the
expense's own `transaction_id` points at the contradiction. Once `status = Paid`, only `notes`
and attachments should be editable; a wrong amount is corrected by reversing the posting.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` (via `transaction_id`, and any reversals) | **view** | **yes, strictly** | `reports.view_financials` | reference, date, debit, credit, amount, is_reversal |

One expense produces one posting today (`transaction_id` is a single column), but a reversal
adds a second row referencing it — so the table should show the posting *and* anything that
reverses it, or a reversed expense will look paid and correct. Strictly read-only (ADR-003).

Attachments (receipts) belong on the edit page if Media Library is wired here — worth
checking, since a expense without a receipt is the one an accountant will query.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `approve` | row | `Draft` or `PendingApproval` | **nothing** | raw `update()` — should be a service | writes the approval trail inline — gap 5 |
| `pay` | row | `status === Approved` | **nothing** | `ExpensePoster` + `AccountingService::post()` | **opens `DB::transaction` in the closure** — gap 2 |
| `ViewAction` | row | always | **nothing** | — | keep |
| `EditAction` | row | always | **nothing** | — | must freeze once paid — gap 6 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 4 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Any staff role can create, approve, pay and bulk-delete expenses.
   **Approval and payment are the same person's click as creation**, which removes the only
   control the flow has. At minimum: create is broad, `approve` and `pay` require a
   permission, and the two should not be the same one.
2. **🔴 The `pay` action is a service in disguise.** `ExpenseResource.php:177-191` opens a
   `DB::transaction`, resolves a `FinancialAccount`, calls the Poster, calls
   `AccountingService::post()`, then updates five columns on the expense — all inside a
   Filament closure. Per ADR-013 the **transaction boundary and orchestration belong in a
   service**; the resource should call `ExpenseService::pay($expense, $method, $account, $by)`.
   As written, any second caller (an import, a scheduled recurring expense, a test) must
   duplicate the sequence, and there is no single place to add the guard in gap 3.
3. **🔴 Nothing prevents paying twice.** `pay` is `visible()` only for `Approved`, and it sets
   `Paid` — so the UI guards it, but the guard is presentation, not invariant. A double
   submit, a stale tab, or a second caller posts a second transaction and overwrites
   `transaction_id`, orphaning the first posting in an append-only ledger. The service in
   gap 2 should refuse to pay a non-approved or already-paid expense.
4. **🔴 `DeleteBulkAction` on posted expenses.** Same shape as elsewhere: the ledger rows
   survive, the expense does not, and the P&L stops reconciling to anything you can open.
5. **🟡 `approve` holds business rules.** `ExpenseResource.php:150-155` sets status, approver
   and timestamp with a raw `update()`. Small, but it is the approval audit trail being
   written by the UI layer.
6. **🟡 Nothing frozen after payment** — see Edit.
7. **🟡 No filters**, including no pending-approval queue — see Index.
8. **🟡 Deprecated `->actions([...])`.**
9. **🔵 `car_id` not conditional on the category** — see Create.

## Checklist

- [ ] Add `canAccess()`; separate create from approve from pay
- [ ] Extract `ExpenseService::pay()` — transaction boundary and orchestration out of the action
- [ ] Make the service refuse to pay a non-approved or already-paid expense; add a test
- [ ] Move `approve` into the service too
- [ ] Remove `DeleteBulkAction`; guard single delete on having a posting
- [ ] Freeze all fields except notes/attachments once `Paid`
- [ ] Add status / date-range / category / vendor / car / branch filters; default to pending approval
- [ ] Show `car_id` only for `is_car_related` categories, and require it there
- [ ] Add a read-only, gated postings table to the view page, including reversals
- [ ] Confirm receipts are attachable via Media Library on a private disk
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

A posting-matrix test asserting both legs and the sign of an expense payment is required
before touching the posting path.

By hand: approve and pay an expense, confirm one transaction appears with the expected debit
and credit. Then try to pay it again from a second browser tab and confirm it is refused
rather than posting twice. Confirm the expense breakdown report at `/admin/reports` moves by
the same amount.
