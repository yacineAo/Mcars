# 24 — PaymentSchedule (Payments)

**Model:** `App\Models\PaymentSchedule` · **Slug:** `/admin/payment-schedules` · **Status:** ✅ done

Closes **REQ-07** (instalment plans). Read
[`../05-accounting-model.md`](../05-accounting-model.md) — instalment payments are **E18**, and a
waived line posts **nothing** (see the note under E21).

## What it is for

Instalment plans — a long rental or a corporate account paying in monthly parts. Each row is
**one instalment**, not a plan header: the table is `schedulable_type`, `schedulable_id`,
`customer_id`, `sequence`, `due_date`, `amount`, `status`, `reminder_sent_at`, `notes`. A plan is
a set of rows sharing a `schedulable`.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | grouped by plan ("Booking #12" → "1 of 6"), overdue / due-this-month / status / customer filters, sequence "n of N" |
| create | ❌ | **by design** — the generator is the only creation path (`canCreate()` is false) |
| view | ❌ | plan view = the grouped index; no per-instalment page needed |
| edit | ✅ | `due_date` editable only while pending; amount, customer, sequence and status are never editable by hand |
| row actions | ✅ | `mark_paid`, `reschedule`, `waive` (all unpaid-only), Edit |
| header / toolbar actions | ✅ | `generate_plan` (the `Money::allocate()` split) — **no bulk actions** |
| relation managers | ❌ | none — the plan is read as a group on the index |
| `canAccess()` | ✅ | gated on `reports.view_financials` |

Confirmed good: `Money::allocate()` is the only way instalments are split
(`PaymentScheduleService::generate()`), so a plan always sums **exactly** to its total — the
centime case is tested. `payment_schedules.amount_paid` does not exist; the paid figure is
derived from `payment_schedule_allocations`, the join that ties each payment to the line it
settled. There is no `DeleteBulkAction`, and `canDelete()` is false: plans are financial
commitments settled by marking a line paid or waived, never by deleting evidence.

## What was done (closing the gaps)

1. **`canAccess()`** on `reports.view_financials` (same gate as `DepositResource` and
   `OwnerInstallmentResource`); row-level `canView` / `canEdit` check `userCanReachBranch()`,
   and the list query is branch-pinned server-side via `pinToAccessibleBranches()` — the
   branch-picker options in the generate dialog are pinned too.
2. **The generator is the only way in.** `PaymentScheduleService::generate()` locks the parent
   row (`lockForUpdate`) so two clerks cannot create a second plan, rejects a parent without a
   customer, refuses a non-positive total or a zero-instalment plan, and splits the total with
   `Money::allocate()` into monthly lines (no-month-overflow dates) in one transaction. The
   header `generate_plan` action drives it; it re-derives branch reachability from the submitted
   ids server-side.
3. **`DeleteBulkAction` removed; creation by hand forbidden.** `canCreate()` returns false and
   there is no create page or page action; `canDelete()` returns false and bulk actions are
   empty. The `DeleteAction` is not even configured.
4. **Settlement, reschedule and waiver, all through the service:**
   - `mark_paid` → `recordPayment()`: takes the money against the parent booking, posts it
     (E18), writes the `payment_schedule_allocation`, and marks the line paid — in one
     transaction. The payment posts as the instalment's amount, not the booking's total.
   - `reschedule` → `reschedule()`: moves `due_date`, unpaid lines only.
   - `waive` → `waive()`: status-only transition (a line is never posted by itself, so there
     is nothing to reverse — see the accounting note). The reason is mandatory and the
     decision is stamped with `waived_at` / `waived_by_id` (migration
     `2026_08_15_000000_add_waiver_audit_to_payment_schedules`).
   All three assert unpaid (`pending | overdue`) under `lockForUpdate`; a paid or waived line
   cannot be changed again.
5. **New filters:** overdue (`due_date < today` and not paid/waived), due-this-month, status,
   customer — the customer options are branch-pinned as well as the rows.
6. **The plan reads as a plan.** The index groups by the morph pair `schedulable_type |
   schedulable_id` (never the id alone — a Booking #7 and a Contract #7 are different plans),
   and the sequence column renders "n of N" with N from a correlated subquery — a window
   function would shrink "1 of 3" to "1 of 1" the moment any filter was applied, so the plan
   size is computed independently of the outer WHERE.
7. **`reminder_sent_at` surfaced** as a boolean column (the Phase 8 alert writes it).
8. **Edit restricted to pending.** `due_date` is editable only while the line is pending and
   cannot be moved into the past from the form; the form never lets customer, amount, sequence
   or status be typed.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/PaymentScheduleResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/SchemaConventionsTest.php
```

`PaymentScheduleResourceTest` covers the exact split (`10000.00 / 3 = 3333.34 + 3333.33 +
3333.33`, summing back to the centime), the one-plan-per-schedulable rule, the posted E18
payment with its allocation, paid-line immutability, the settlement/reschedule/waive actions,
the filter-safe plan count, the morph-pair group key, access gating, branch scoping and the
missing create/delete paths. `SchemaConventionsTest` asserts no stored `amount_paid` on the
table.

By hand: generate "18 000 DZD over 6 months from March" and confirm the instalments sum exactly
to 18 000.00; tick Overdue and confirm a filtered plan still reads "3 of 6", never "3 of 3";
record a payment against one line and confirm the ledger row and the allocation agree; waive a
pending line and confirm the reason is recorded and the line leaves the collections queue.
