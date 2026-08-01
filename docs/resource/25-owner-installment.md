# 25 — OwnerInstallment (Payments)

**Model:** `App\Models\OwnerInstallment` · **Slug:** `/admin/owner-installments` · **Status:** ✅ done

Closes **ADV-07** (owner payouts). Read
[`../05-accounting-model.md`](../05-accounting-model.md) — posting **E32** is the monthly
accrual, and the owner payable is account **2200**.

## What it is for

What the business owes each car owner. Mcars runs owned and managed cars side by side; a managed
car earns its owner a monthly rent, accrued into account 2200 and paid out later. This screen is
where the accountant accrues the month and sees what is outstanding.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | unaccrued / overdue / period / owner / car filters |
| create | ✅ | auto-numbers inside the agreement |
| view | ✅ | Money, Period, Owner & Car, Notes + History relation group |
| edit | ✅ | money fields frozen once accrued; notes and `waived_reason` stay editable |
| row actions | ✅ | `accrue`, View, Edit, single Delete — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` only — **no bulk actions** |
| relation managers | ✅ | Transactions (accrual posting) and Payments (against 2200), both read-only |
| `canAccess()` | ✅ | gated on `reports.view_financials` |

`accrue` is correctly built: it delegates to `PaymentService::accrueOwnerInstallment()` and is
`visible()` only while `! $record->isPostedToLedger()`, so it cannot double-post through the UI.
The service now also stamps `accrual_transaction_id` on the instalment (it was posted to the
ledger before without the pointer), in the **same transaction as the posting** — a crash cannot
leave E32 on the ledger with the row still reading unaccrued. The pointer, the unaccrued queue,
the accrued indicator and the row freeze all read the same fact.

Confirmed good: `owner_installments` has **no `amount_paid` column** — CLAUDE.md bans it
explicitly, and the paid figure is derived in `ReportService::installmentPaidAmount()`, which
sums ledger rows against account 2200. The invariant holds. The index column resolves the same
predicate through `ReportService::installmentsPaidAmounts()` — one grouped query per request,
never a per-row sum, never a sum written in the resource.

## What was done (closing the gaps)

1. **`canAccess()`** on `reports.view_financials` (same gate as `DepositResource`); row-level
   `canView` / `canEdit` / `canDelete` check `userCanReachBranch()`, and the list query is
   branch-pinned server-side via `pinToAccessibleBranches()`.
2. **Bulk delete removed.** Only a single row `DeleteAction` remains, `visible()` only while
   `! isPostedToLedger()` — the visibility is explicit because default action authorization
   consults the model policy, which this codebase does not use. `canDelete()` enforces the same
   rule for callers that do consult it.
3. **Edit frozen once accrued.** `amount_due`, `period_month`, owner and car are `disabled()`
   once `isPostedToLedger()`; notes and `waived_reason` remain editable. The resource-level
   `canDelete`/action visibility and the freeze all read the same pointer.
4. **New filters:** unaccrued (`accrual_transaction_id IS NULL`), overdue (`due_date` before
   today, status not Paid/Waived), `period_month`, owner, car.
5. **New columns:** accrued indicator (badge from `accrual_transaction_id`, not inferred from
   the action) and derived paid figure (summed once per query, gated on
   `reports.view_financials`).
6. **View page** with Money, Period, Owner & Car (linked) and Notes sections, plus a History
   relation group: the accrual transaction (read-only ledger posting) and the payments against
   2200 for this instalment (a query, not a `hasMany` — read-only).
7. **`total_installments = 999` resolved.** The migration
   `2026_08_13_000000_make_owner_installments_total_installments_nullable` makes the column
   nullable; `OwnerStatementService::generateOneInstallment()` now writes `null` for open-ended
   agreements, so a report never prints "3 of 999". Existing rows keep whatever they had (down
   restores the 999 sentinel). The three relation managers that render the sequence display
   `seq / total` show just the sequence number when the total is null.
8. **Deprecated `->actions([...])` → `->recordActions([...])`.**
9. Manual creation auto-numbers: `sequence_number` is max-per-agreement + 1 in
   `mutateFormDataBeforeCreate()`.

An owner's full instalment history belongs on `CarOwnerResource`'s view page rather than here —
see the Fleet audit for that resource.

## Checklist

- [x] Add `canAccess()` on `reports.view_financials`
- [x] Remove `DeleteBulkAction`; guard single delete on being accrued
- [x] Freeze `amount_due`, `period_month`, owner and car once accrued
- [x] Add unaccrued / overdue / period / owner / car filters
- [x] Add accrued-indicator and derived paid columns (gated, from `ReportService`)
- [x] Add a view page with the accrual posting and payments against it
- [x] Resolve `total_installments = 999` — nullable open-ended
- [x] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/OwnerInstallmentResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
```

`OwnerInstallmentResourceTest` (21 tests) covers access, the frozen form, the delete rule, the
absence of bulk actions, the derived paid figure, every filter, the E32 accrual through
`PaymentService`, auto-numbering, the view page and the relation managers, the open-ended null
total and branch scoping. `Phase9Test` covers the owner statement from the ledger;
`SchemaConventionsTest` asserts `owner_installments.amount_paid` does not exist and must stay
green.

By hand: accrue a month, confirm one E32 posting crediting 2200, confirm the action disappears
and cannot be re-run. Pay the owner, then confirm the owner statement report at `/admin/reports`
shows the instalment as paid without any stored column changing.
