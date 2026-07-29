# 28 — EmployeeAdvance (HR)

**Model:** `App\Models\EmployeeAdvance` · **Slug:** `/admin/employee-advances` · **Status:** 🔴 needs work

Supports **ADV-07**.

## What it is for

A salary advance — the employee takes money now and it comes off a future payroll. The recovery
link is `recovered_in_payroll_item_id`: while it is null the advance is still owed.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | employee, amount, `advanced_on`, reason, financial account |
| view | ❌ | not needed |
| edit | ✅ | fully open |
| row actions | ❌ | **none** |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | **absent** |

At 79 lines this is plain CRUD over a record that pays out cash and creates a claim on future
salary. `status` and `payment_id` exist on the table, so the lifecycle was designed — but nothing
in the UI drives it.

## Should be

### Index
Show employee, amount, `advanced_on`, status, and **whether it has been recovered** (derived from
`recovered_in_payroll_item_id`). Filter by employee, status, and **outstanding** — which is the
only question anyone asks of this list: who still owes money.

### Create
Paying an advance moves cash and must post to the ledger. `financial_account_id` and `payment_id`
are on the table, so the intended flow is create → pay → recover in payroll. Confirm the payout
actually posts; if it does not, the business has cash leaving the till with no ledger entry, which
would be a 🔴 against the central invariant rather than a UI gap.

### View
**Not needed.** Eight fields. What matters — the recovery — belongs on the employee's view page
(see [`27-employee.md`](27-employee.md)) and on the payroll run's items.

### Edit
Freeze `amount`, `employee_id` and `advanced_on` once paid or recovered. An advance whose amount
changes after recovery leaves the payroll item and the advance disagreeing.

### Relations
None here. The advance appears as a line on a payroll run and as a row on the employee — both
covered by those resources.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| _(none)_ | row | — | — | — | no actions, and **no approval flow** — gap 2 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 4 |
| _needed_ | row | by status | payroll permission | a service | submit / approve / reject; refuse self-granted — gap 1 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Any staff role can grant themselves a salary advance. This is the
   clearest self-dealing path in the panel: create an advance to your own employee record, for
   any amount, with no approval. It needs the payroll permission from
   [`27-employee.md`](27-employee.md) gap 1, and creating an advance for oneself should be
   refused outright.
2. **🔴 No approval flow in the UI.** `status` exists on the table but there are no
   submit/approve/reject actions — so either advances are approved outside the system or the
   status is decorative. Whichever it is, the screen currently lets money out with no recorded
   authorisation. (Compare [`15-expense.md`](15-expense.md), which does have an approve action —
   the inconsistency suggests this one was simply not finished.)
3. **🔴 Recovery instalments and `Money::allocate()`.** If an advance is recovered over several
   months, the split must use `Money::allocate()` — which
   [`README.md`](README.md) finding 9 shows is never called anywhere. `recovered_in_payroll_item_id`
   is a **single** column, so the schema supports recovery in one payroll item only; multi-month
   recovery is not modelled. Establish which is intended before building either.
4. **🔴 `DeleteBulkAction`** on advances that may be paid and recovered.
5. **🟡 No outstanding filter** — the one useful view of this list.
6. **🟡 Nothing frozen after payment** — see Edit.
7. **🟡 No row actions** — same discoverability pattern as [`16-extra.md`](16-extra.md) gap 1.

## Checklist

- [ ] Add `canAccess()` with the payroll permission; refuse self-granted advances
- [ ] Decide and build the approval flow, or remove `status` if it is genuinely unused
- [ ] Confirm the payout posts to the ledger through `AccountingService`
- [ ] Decide whether multi-month recovery is in scope; if so, model it and use `Money::allocate()`
- [ ] Remove `DeleteBulkAction`
- [ ] Add outstanding / employee / status filters and a recovered indicator
- [ ] Freeze amount, employee and date once paid
- [ ] Add explicit row actions

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

By hand: grant an advance, confirm cash leaving the till appears in the ledger, then run payroll
for that employee and confirm the advance is recovered and the net pay reduced by exactly the
advance amount.
