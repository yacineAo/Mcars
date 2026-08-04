# 29 — Commission (HR)

**Model:** `App\Models\Commission` · **Slug:** `/admin/commissions` · **Status:** ✅ done

Supports **ADV-07**.

## What it is for

What a sales agent earned on a booking. Recorded per booking, then swept into a payroll run —
`payroll_item_id` is null until it is paid. `basis_amount`, `rate` and `amount` are all stored,
which is the right choice: the commission is fixed at the moment it is earned and must not move
when a price list changes later.

## Decisions taken

- **`amount` is never typed.** `CommissionService` computes `basis × rate / 100` through
  `Money` (integer minor units, half-up) on both create and update, so the stored figure always
  agrees with its own basis and rate. The form has no amount field; the create page has no status
  field either.
- **The lifecycle is owned by the payroll flow.** The form cannot write `status` and
  `CommissionService` re-asserts the existing one on update — `pending → paid` moves with the
  sweep (E59 in `PayrollPoster`), `cancelled` is a payroll-flow decision, never a typed edit. The
  sweep stamp `payroll_item_id` is equally untyped: a crafted one on create or update is stripped,
  so a commission can never claim payment without the payroll run behind it. The
  `commissions.status` CHECK constraint shipped with the enum (fresh migrations only, like the
  advance's).
- **Freeze once paid.** A commission with `payroll_item_id` set keeps only `notes` editable —
  employee, booking, basis, rate and `earned_on` are disabled in the form and re-asserted
  server-side against a crafted payload — and the service refuses to write a commission whose
  status is `paid` at all. Before payment, correcting the rate is legitimate and the service
  recomputes the amount.
- **Self-dealing is refused twice.** The create form rejects an employee whose `user_id` is the
  acting user, and `CommissionService` re-checks it at write time — a crafted create cannot get
  around it. The service also refuses an employee who does not exist or whose branch the acting
  user cannot reach: the form pins the options, the payload re-checks the fact.
- **No delete path.** A commission is money: unpaid it sits in the sweep queue, paid it has an
  E59 posting behind it. Neither is deleted.
- **Automatic raising on booking completion is out of scope** — the commission is created from
  the resource, by hand, until the gap-2 feature (auto-raise at booking completion) is built.
  The service API (`create`/`update` over `EmployeeId + basis + rate + earned_on`) is the seam
  that feature will call.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | employee, booking, `earned_on`, basis, rate, amount, status badge, **paid in** (derived from `payrollItem.payrollRun.period_month`) |
| create | ✅ | employee (pinned, self-dealing refused), booking (pinned, optional), basis, rate, `earned_on`, notes; amount computed by the service |
| view | ✅ | added — the one line, with a link to the payroll run that swept it in, once swept |
| edit | ✅ | notes only once paid; terms re-asserted server-side |
| row actions | ✅ | Edit (the lifecycle has no human step — it moves with payroll) |
| header / toolbar actions | ✅ | `CreateAction`; **no `DeleteBulkAction`** |
| relation managers | ❌ | none — the commission is a line on the employee's view page |
| `canAccess()` | ✅ | `hr.view_salary`; writes additionally `hr.manage` |

## Filters

- **Unpaid** (default ON): `payroll_item_id IS NULL` — the month-end sweep queue. Off shows
  everything already swept into a payroll run.
- Employee (pinned to reachable branches), `earned_on` range (inclusive, plain date column).

## Service

`App\Services\Payment\CommissionService::create(array, int $userId)` and
`update(Commission, array, int $userId)` — refuse self-granted records and employees outside the
acting user's reachable branches, own the `status`, the computed `amount` and the sweep stamp
(`payroll_item_id` is stripped on create, re-asserted on update), and `update` refuses a
commission already swept into payroll or in a `paid` state. All three throw `DomainException`; the
create/edit pages surface it as a validation error on `employee_id` instead of a 500.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CommissionResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
```

By hand: create a commission, confirm `amount` matches basis × rate to the centime, sweep it into
a payroll run, and confirm it can no longer be edited.
