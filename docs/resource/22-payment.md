# 22 — Payment (Payments)

**Model:** `App\Models\Payment` · **Slug:** `/admin/payments` · **Status:** ✅ audited — fine

Closes **REQ-07**. Read [`../05-accounting-model.md`](../05-accounting-model.md).

## What it is for

Every movement of money in or out, in its own right — as opposed to the booking or expense
that caused it. A receptionist records cash at the counter (usually from the booking screen,
not here); an accountant comes here to find a payment, check it reached the ledger, and post
it if it did not.

## State after audit

| Surface | State | Notes |
|---|---|---|
| index | ✅ | date-range on `paid_at`, `method`, `direction`, `status`, branch (`branches.view_all`), and the **not-yet-posted** toggle — the accountant's queue |
| posted indicator | ✅ | `ledger_transactions_exists` badge, selected once per query via `withExists` — never per-row |
| create | ✅ | posts to the ledger in `afterCreate()`; failure surfaces as a persistent notification with the `post_to_ledger` retry path |
| view | ✅ | infolist with the payable link and ledger postings — the reconciliation screen |
| edit | ✅ | frozen once posted |
| row actions | ✅ | `post_to_ledger`, View, Edit — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction`; **no bulk actions** |
| relation managers | ✅ | `TransactionsRelationManager` — read-only, gated `reports.view_financials` |
| `canAccess()` | ✅ | `cash_sessions.operate` **or** `reports.view_financials` |
| branch pinning | ✅ | both halves go through `ChecksBranchAccess`: `getEloquentQuery()` pins the list, `canView`/`canEdit` re-check the record, and an empty accessible set **fails closed** |

## What changed

1. **`canAccess()` added** — `cash_sessions.operate || reports.view_financials`, deliberately
   wider than [Deposit's](23-deposit.md) gate on `reports.view_financials` alone: the role
   matrix ([`../02-filament-panels.md`](../02-filament-panels.md)) gives the receptionist
   "payments + cash only" on Finance, and a receptionist who cannot open the payments screen
   cannot retry a payment whose posting failed. Mirrors `CashSessionResource`'s two-gate split:
   the till operator sees the cash side, the financial reader sees the books.
2. **`DeleteBulkAction` removed, `canDelete()` returns false unconditionally.** A payment row
   is money-movement evidence: unposted payments are completed through `post_to_ledger`, never
   deleted; posted payments are ledger history and the append-only rule says reverse, don't
   delete. The postings would survive a delete and cash-flow would reconcile to rows you could
   not open.
3. **Edit freezes once posted.** `reference` (a document number) is always disabled on edit;
   `amount`, `method`, `paid_at`, `direction`, `status`, `customer_id` and
   `financial_account_id` become disabled once `isPostedToLedger()`. The ledger rows are
   append-only (ADR-003), so editing the payment afterwards would make the two disagree with no
   trace. `status` freezes with the posting even though nothing posts on it — a flip to
   `bounced`/`refunded` would assert money movement the ledger never recorded. Notes and
   `external_reference` stay editable — the row's own paperwork.
4. **Method-specific fields shown conditionally.** `external_reference` relabels by method
   (RIB / CCP account / BaridiMob number / cheque number / card reference) and shows only for
   the methods that carry one; `cheque_due_date` shows only for `cheque`. Both freeze once
   posted.
5. **Not-yet-posted filter added** — a toggle that filters to `whereDoesntHave('ledgerTransactions')`,
   the queue the `post_to_ledger` action exists to clear.
6. **Posted indicator column** — `withExists('ledgerTransactions')` in `getEloquentQuery()`
   feeds a badge column; one EXISTS in the query, never one query per row.
7. **View page** — infolist (identity, money, payable link, notes) plus the gated postings
   relation manager, mirroring `ExpenseResource`. The payable morph resolves to a link only
   when the viewer can open the target resource — a dead link into a 403 is worse than plain
   text.
8. **`->actions([...])` → `->recordActions([...])`** and enum-backed options
   (`PaymentDirection::options()`, `PaymentStatus::options()`) replace the hardcoded subsets —
   the poster already handles every enum case, so the form no longer hides them.

## Invariants held

- **The resource never writes to `transactions`.** `afterCreate()` and `post_to_ledger` both
  delegate to `PaymentService::recordPayment()`, the only writer (`AccountingService`).
- **`post_to_ledger` is the retry path, not a double-post.** It is `visible()` only while
  `! $record->isPostedToLedger()`; after a successful create-posting it is never offered.
- **Freeze is the edit guard, not the only guard.** Even an unposted payment cannot be deleted,
  and branch pinning is server-side in the query, not a filter the user can clear.
- **Branch scoping fails closed.** `ChecksBranchAccess` (`app/Filament/Admin/Concerns/`) owns both
  halves — the query constraint and the record check — because they were written separately three
  times (Payment, Expense, CashSession) and had drifted: the query asked `accessibleBranchIds()`,
  which honours the `branch_user` pivot, while the record check compared `users.branch_id`, so a
  pivot-granted branch showed rows the view page then refused. An empty accessible set now yields
  `1 = 0` rather than an unconstrained query — an unconfigured user sees nothing, not everything.
- **The split at the receivable (E10–E14 / E19) is unchanged** — `PaymentPoster` reads the
  outstanding receivable through `ReportService`; no sum moved into this resource.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/PaymentResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

`PaymentResourceTest` (18 tests): the two-gate access split, branch pinning (including the
fail-closed empty set and pivot-granted branches), the posted
indicator, the not-yet-posted queue, method/direction/status/date filters, auto-posting on
create, conditional method fields, the post-posting freeze (including a refused amount edit),
the `post_to_ledger` retry, the payable link on the view page, and the
`reports.view_financials` gate on the postings relation manager.
