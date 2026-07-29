# 27 — Employee (HR)

**Model:** `App\Models\Employee` · **Slug:** `/admin/employees` · **Status:** 🔴 needs work

Supports **ADV-07**. See [`../tasks/phase-06-payments-deposits.md`](../tasks/phase-06-payments-deposits.md).

## What it is for

Staff records: who works here, their job, their hire date and their base salary. Referenced by
payroll, commissions and advances, and by bookings via `driver_employee_id` when a car goes out
with a driver.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | |
| view | ❌ | see Relations |
| edit | ✅ | |
| row actions | ❌ | **none** — rows reachable only via Filament's default record URL |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none, though `Employee hasMany payrollItems, advances, commissions` |
| `canAccess()` | ❌ | **absent** |

## Should be

### Index
Show employee number, name, job title, department, hire date and active status. **Not base
salary** — see gap 1. Filter by department, job title and active.

### Create
Fine. `employee_number` should be generated rather than typed, via `SequenceGenerator` — which
**must run inside a transaction** or it throws.

### View
Worth adding, because an employee's payroll history is the reason to open the record. It is also
where the salary belongs, behind a gate, rather than on a list anyone can see.

### Edit
`base_salary` changes are the sensitive edit here. A change should be effective-dated rather than
overwriting, or a historical payroll run cannot be explained from the current record. If
effective dating is out of scope, the change should at least be recorded in the activity log
(Phase 10) — worth confirming `Employee` uses `LogsActivity`.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `payrollItems` | **view** | yes | salary permission | run period, base, commissions, advances recovered, net |
| `advances` | **view** | yes | salary permission | advanced on, amount, status, recovered in |
| `commissions` | **view** | yes | salary permission | earned on, booking, basis, rate, amount, status |

All three expose pay, so all three need the same gate as the salary field. An employee's own
record showing their own pay is fine; one employee seeing another's is not — worth deciding
whether that distinction is enforced here or simply by restricting the resource.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| _(none)_ | row | — | — | — | no row actions — gap 3 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — prefer `is_active`, gap 2 |
| _needed_ | row | always | payroll permission | — | add `EditAction`; salary must be gated |

## Gaps and risks

1. **🔴 `base_salary` is unprotected.** There is no `canAccess()` and no field-level gate, so any
   staff role — a receptionist, a maintenance officer — can open the resource and read every
   colleague's salary, and edit it. This is the most sensitive personal data in the system after
   customer NIN, and it is the least protected. It needs a permission of its own;
   `reports.view_financials` is the wrong gate conceptually (this is payroll confidentiality, not
   financial reporting) so a new permission is likely warranted — which means adding it to
   `RolePermissionSeeder`, since [`README.md`](README.md) finding 8 shows an unseeded permission
   silently denies everyone.
2. **🔴 `DeleteBulkAction` on employees** referenced by payroll items, commissions, advances and
   bookings. Use `is_active`.
3. **🟡 No row actions**, so there is no visible edit affordance and no delete/deactivate path
   from the list. Rows are still clickable because `ListRecords` installs a default record URL
   (`vendor/filament/filament/src/Resources/Pages/ListRecords.php:162`), so this is
   discoverability rather than a dead screen — the same pattern as
   [`16-extra.md`](16-extra.md) gap 1.
4. **🟡 No view page**, so payroll history is unreachable from the employee.
5. **🟡 `base_salary` overwritten rather than effective-dated** — see Edit.
6. **🟡 `employee_number` typed rather than generated.**

## Checklist

- [ ] Add `canAccess()` and a dedicated payroll-confidentiality permission; seed it
- [ ] Remove `base_salary` from the index; gate it on the view page
- [ ] Remove `DeleteBulkAction`; prefer `is_active`
- [ ] Add explicit `EditAction`
- [ ] Add a view page with the three gated relation managers
- [ ] Generate `employee_number` via `SequenceGenerator`, inside a transaction
- [ ] Decide on effective-dated salary changes, or confirm activity logging covers it
- [ ] Add department / job-title / active filters

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/PanelAccessTest.php
docker compose exec app ./vendor/bin/pest tests/Unit/SequenceGuardTest.php
```

`SequenceGuardTest` lives in `tests/Unit` because it asserts behaviour at transaction level 0 —
if you add sequence generation here, follow that placement.

By hand: open the resource as a receptionist and confirm no salary is visible anywhere, then as
an HR role and confirm it is.
