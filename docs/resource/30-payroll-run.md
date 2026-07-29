# 30 — PayrollRun (HR)

**Model:** `App\Models\PayrollRun` · **Slug:** `/admin/payroll-runs` · **Status:** 🔴 needs work

Closes **ADV-07**. Read [`../05-accounting-model.md`](../05-accounting-model.md) — payroll
posts salaries, and the run is the batch.

## What it is for

One month's payroll for a branch. It gathers the employees' items — base salary, commissions
earned, advances to recover — is approved, then paid, which posts the whole batch to the ledger.
It is the largest single posting the business makes each month.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | `period_month`, branch, notes |
| view | ❌ | **absent** — the run's items cannot be seen |
| edit | ✅ | |
| row actions | ✅ | `approve`, `pay`, Edit — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none, though `PayrollRun hasMany items` |
| `canAccess()` | ❌ | **absent** |

Schema is sensible: `approved_by_id`, `approved_at`, `paid_at` give the run an audit trail, and
`payroll_runs` holds no total column — the total is the sum of its items, derived.

## Should be

### Index
Show `period_month`, branch, status, the derived total, and employee count. Filter by status
(defaulting to draft/pending), period and branch. The total must come from a service or
`ReportService`, not be summed in the resource.

### Create
A run is generated for a period, not typed: creating it should gather every active employee's
base salary, their unrecovered advances (`employee_advances.recovered_in_payroll_item_id IS NULL`)
and their unpaid commissions (`commissions.payroll_item_id IS NULL`) into items. Confirm that
gathering exists in a service; if it does not, a run is an empty shell and the screen cannot do
its job.

Prevent two runs for the same period and branch — paying a month twice is the worst outcome this
screen can produce.

### View
**Add one.** A payroll run's substance is its items, and there is currently no way to see them —
you can approve and pay a batch whose contents you cannot inspect. That is the single most
important change here.

Sections: period, branch, status with the approval trail, derived totals (gross, advances
recovered, commissions, net). Then the items table.

### Edit
Freeze once approved. After `pay`, the whole run is immutable — the postings exist. `notes` only.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `items` | **view** | yes once approved; editable while draft | `reports.view_financials` | employee, base salary, commissions, advances recovered, net |
| `transactions` | **view** | **yes, strictly** | `reports.view_financials` | reference, date, debit, credit, amount |

While a run is draft, adjusting an item is legitimate (a correction before approval); once
approved it must be read-only. That is a relation manager whose write actions are conditional on
the parent's status — worth stating explicitly so it is not built fully open.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `approve` | row | see resource | **nothing** | raw update | must be gated separately from pay — gap 1 |
| `pay` | row | `status === Approved` | **nothing** | `PaymentService::payPayroll()` | **opens `DB::transaction` and sets status in the closure** — gaps 2, 3 |
| `EditAction` | row | always | **nothing** | — | must freeze once approved |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 5 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Any staff role can create, approve and pay payroll — including
   employees whose own salary is in it. Approval and payment must be gated, and separately from
   each other; a receptionist should not see salaries at all.
2. **🔴 The `pay` action owns the transaction boundary** (`PayrollRunResource.php:97-101`): it
   opens `DB::transaction`, calls `PaymentService::payPayroll()`, then updates the status itself.
   Per ADR-013 the boundary and the orchestration belong in the service —
   `PaymentService::payPayroll()` should set the status as part of its own transaction, so a
   second caller cannot post the batch without marking it paid. Identical in shape to
   [`15-expense.md`](15-expense.md) gap 2.
3. **🔴 Nothing prevents paying a run twice** at the invariant level. `visible()` restricts the
   action to `Approved` status, but that is presentation. Because the status update lives in the
   action rather than the service (gap 2), a second caller — an import, a test, a stale tab —
   can post the batch again. For payroll this is the highest-consequence double-post in the
   system.
4. **🔴 No view page, so a run is approved and paid unseen** — see View.
5. **🔴 `DeleteBulkAction` on paid runs.** Deleting a paid run leaves a month of salary postings
   with nothing behind them.
6. **🟡 Duplicate runs per period/branch not prevented** — see Create.
7. **🟡 No derived total** on the index.
8. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Add `canAccess()`; separate approve from pay; hide salaries from non-HR roles
- [ ] Move the transaction boundary and the status update into `PaymentService::payPayroll()`
- [ ] Make the service refuse a run that is not approved or is already paid; test it
- [ ] Add a view page with the items table and the approval trail
- [ ] Add the items relation manager — writable only while draft
- [ ] Remove `DeleteBulkAction`
- [ ] Prevent two runs for the same period and branch
- [ ] Add derived total and employee-count columns
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

A posting-matrix test for the payroll batch is required — both legs, and the sign — before
touching `payPayroll`.

By hand: generate a run for a month with one employee holding an advance and a commission.
Confirm the items show all three components, that the net is right, and that paying it posts
once. Then attempt to pay again from a second tab and confirm refusal.
