# 22 — Payment (Payments)

**Model:** `App\Models\Payment` · **Slug:** `/admin/payments` · **Status:** 🔴 needs work

Closes **REQ-07**. Read [`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

Every movement of money in or out, in its own right — as opposed to the booking or expense
that caused it. A receptionist records cash at the counter (usually from the booking screen,
not here); an accountant comes here to find a payment, check it reached the ledger, and post
it if it did not.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters (unlike most of the panel) |
| create | ✅ | |
| view | ❌ | see Relations |
| edit | ✅ | nothing frozen after posting |
| row actions | ✅ | `post_to_ledger`, View, Edit — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | **absent** |

`post_to_ledger` is correctly built: it delegates to `PaymentService::recordPayment()` and is
`visible()` only while `! $record->isPostedToLedger()` (`PaymentResource.php:78-91`). The
resource does not write to `transactions` itself.

## Should be

### Index
Already has filters — extend rather than build. Wanted: date range on `paid_at`, `method`,
`direction` (inbound/outbound), and **"not yet posted"**, which is the accountant's real
queue and the reason the `post_to_ledger` action exists. Branch with `branches.view_all`.

Add a column showing whether the payment has reached the ledger. Right now the only way to
know is that the action is still offered.

### Create
Payment methods are Algerian — cash, CCP, BaridiMob, bank transfer — and the
method-specific fields (RIB, CCP account, BaridiMob number) should show conditionally on
`method`, not all at once.

Creating a payment here, detached from what it pays for, should be the exception: `payable_type`
/ `payable_id` is a morph, and a payment with no payable cannot be reconciled to anything.
Either require a payable or make the "unallocated payment" case explicit and reportable.

### View
Worth adding: the payment, its payable (booking / expense / installment) as a link, and its
ledger postings. That is the reconciliation question this resource exists to answer.

### Edit
**Freeze once posted.** `amount`, `method`, `paid_at`, `financial_account_id` and the payable
must all become read-only once `isPostedToLedger()` — the ledger rows are append-only, so
editing the payment afterwards makes the two disagree with no trace. Notes only, after that.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` | **view** | **yes, strictly** | `reports.view_financials` | reference, date, debit, credit, amount, is_reversal |

Include reversals, or a reversed payment looks settled.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `post_to_ledger` | row | `! isPostedToLedger()` | **nothing** | `PaymentService::recordPayment()` | correctly delegated |
| `ViewAction` | row | always | **nothing** | — | no view page yet |
| `EditAction` | row | always | **nothing** | — | must freeze once posted — gap 3 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 2 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Any staff role can create, edit, post and bulk-delete payments.
   Compare [`23-deposit.md`](23-deposit.md), which correctly gates on
   `reports.view_financials` — the inconsistency inside one navigation group is the tell that
   this was an oversight rather than a decision.
2. **🔴 `DeleteBulkAction` on posted payments.** The postings survive the payment. Cash-flow
   and receivables then reconcile to rows you cannot open.
3. **🔴 Nothing frozen after posting** — see Edit. This is the same defect as
   [`15-expense.md`](15-expense.md) gap 6 and worth fixing as one pattern across the group.
4. **🟡 No "unposted" filter** despite an action that exists purely to clear that queue.
5. **🟡 No ledger-posted indicator** in the table.
6. **🟡 Method-specific fields shown unconditionally.**
7. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Add `canAccess()`, matching DepositResource's gate unless there is a reason to differ
- [ ] Remove `DeleteBulkAction`; guard single delete on being posted
- [ ] Freeze amount / method / paid_at / account / payable once posted
- [ ] Add an "unposted" filter and a posted indicator column
- [ ] Add date-range, method, direction and branch filters
- [ ] Show method-specific fields conditionally
- [ ] Add a view page with the payable link and a read-only, gated postings table
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

By hand: record a payment without posting, confirm it appears in the unposted filter, post it,
confirm the filter empties and the postings show on the view page. Then attempt to edit the
amount and confirm it is refused.
