# 24 — PaymentSchedule (Payments)

**Model:** `App\Models\PaymentSchedule` · **Slug:** `/admin/payment-schedules` · **Status:** 🔴 needs work

Closes **REQ-15** (instalment plans). See
[`../tasks/phase-06-payments-deposits.md`](../tasks/phase-06-payments-deposits.md).

## What it is for

Instalment plans — a long rental or a corporate account paying in monthly parts. Each row is
**one instalment**, not a plan header: the table is
`schedulable_type`, `schedulable_id`, `customer_id`, `sequence`, `due_date`, `amount`,
`status`, `reminder_sent_at`. A plan is a set of rows sharing a `schedulable`.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | one instalment at a time |
| view | ❌ | |
| edit | ✅ | |
| row actions | ❌ | **none** — no mark-paid, no reschedule, no waive |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | the model has only `customer`; no relation to its sibling instalments |
| `canAccess()` | ❌ | absent |

At 78 lines this is the thinnest resource in the Payments group, and the thinness is the
problem: it is a plain CRUD table over rows that are supposed to be generated, reconciled and
chased.

## Should be

### Index
Group by `schedulable` so a plan reads as a plan rather than as scattered rows — sort by
schedulable then `sequence`. Show the customer, sequence as "2 of 6", due date, amount, status,
and whether a reminder has gone out (`reminder_sent_at` exists and is not surfaced).

Filters wanted: **overdue** (`due_date < today AND status != paid`), due-this-month, status,
and customer. Overdue instalments are the collections queue and there is currently no way to
list them.

### Create
**A single instalment should not be the primary way in.** Generating a plan — "18,000 DZD over
6 months from March" — is the actual use case, and it needs a generator that uses
`Money::allocate()` so the parts sum exactly to the total. See gap 1.

### View
Not needed per instalment. What is needed is a **plan view**, which is really a grouped list —
either the grouped index above, or a read-only instalments table on the parent booking or
customer. See [`09-customer.md`](09-customer.md), which already proposes
`paymentSchedules` as a gated relation manager on the customer.

### Edit
`amount` and `due_date` should be editable only while `status` is pending — rescheduling an
unpaid instalment is legitimate, rewriting a paid one is not. Editing an amount must never
silently break the plan total; that is the argument for the generator owning the split.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| sibling instalments (same `schedulable`) | index grouping, or the parent's view | yes | `reports.view_financials` | sequence, due date, amount, status |

The model has no relation to its siblings or to its `schedulable` morph target — adding the
morph relation is a prerequisite for showing a plan anywhere.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| _(none)_ | row | — | — | — | **no actions at all** — cannot mark paid, waive or reschedule (gap 2) |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 3 |
| _needed_ | header | always | new gate | a generator service | generate a plan using `Money::allocate()` — gap 1 |

## Gaps and risks

1. **🔴 `Money::allocate()` is never called anywhere in the application.** Verified: the only
   occurrences of "allocate" in `app/` are the method definition at `app/Support/Money.php:122`
   and an unrelated string in `SequenceGenerator`. CLAUDE.md names `allocate()` as the required
   way to split an amount ("splits instalments without losing a centime") and there is no
   generator using it — instalment amounts are typed in by hand, one row at a time. So a
   6-way split of 10,000 DZD depends on whoever is at the keyboard doing the arithmetic, and
   nothing checks that the parts sum to the total. **This is the gap that makes the resource
   🔴**: a Phase 0 primitive built specifically for this job is unused, and the job is being
   done by hand instead.
2. **🔴 No actions at all.** An instalment cannot be marked paid, waived or rescheduled from
   the UI. Compare `OwnerInstallmentResource`, which has an `accrue` action — the customer-side
   equivalent has nothing. Whether payment allocation happens elsewhere (via
   `PaymentResource`'s morph) needs establishing; if it does, this screen should at least show
   it, and if it does not, instalments can never be settled.
3. **🔴 `DeleteBulkAction`** on rows that may be paid and posted.
4. **🟡 No `canAccess()`** on a money screen, while its sibling `DepositResource` is gated.
5. **🟡 No overdue filter** — the collections queue is unlistable.
6. **🟡 `reminder_sent_at` not surfaced**, though Phase 8 alerts presumably write it.
7. **🟡 No morph relation on the model**, so a plan cannot be assembled.
8. **🟡 Rows not grouped**, so a plan is invisible.

## Checklist

- [ ] Establish whether a generator exists anywhere; if not, add one using `Money::allocate()`
- [ ] Add a test asserting generated instalments sum **exactly** to the total (the centime case)
- [ ] Establish how an instalment gets settled; add mark-paid / waive / reschedule actions
- [ ] Remove `DeleteBulkAction`
- [ ] Add `canAccess()`
- [ ] Add overdue / due-this-month / status / customer filters
- [ ] Group the index by `schedulable`; show sequence as "n of N"
- [ ] Surface `reminder_sent_at`
- [ ] Add the `schedulable` morph relation to the model
- [ ] Restrict editing `amount` / `due_date` to pending instalments

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Unit
```

`Money::allocate()` needs a unit test proving the remainder distribution: splitting 10,000.00
into 3 must yield parts summing to exactly 10,000.00, not 9,999.99.

By hand: create a plan, confirm the instalments sum to the total, take a payment against one
and confirm the instalment reflects it.
