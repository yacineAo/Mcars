# 29 — Commission (HR)

**Model:** `App\Models\Commission` · **Slug:** `/admin/commissions` · **Status:** 🟡 partial

Supports **ADV-07**.

## What it is for

What a sales agent earned on a booking. Recorded per booking, then swept into a payroll run —
`payroll_item_id` is null until it is paid. `basis_amount`, `rate` and `amount` are all stored,
which is the right choice: the commission is fixed at the moment it is earned and must not move
when a price list changes later.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | `->filters([])` **empty** |
| create | ✅ | |
| view | ❌ | not needed |
| edit | ✅ | fully open |
| row actions | ❌ | **none** |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | **absent** |

Confirmed good: `basis_amount` and `rate` are stored alongside `amount`, so the figure is
reproducible from the record rather than recomputed on display. That avoids the drift this kind of
screen usually has.

## Should be

### Index
Show employee, booking, `earned_on`, basis, rate, amount, and status. Filter by employee,
`earned_on` range, and **unpaid** (`payroll_item_id IS NULL`) — the sweep queue for month-end.
That is the only filter that matters here and there are currently none at all.

Group or total by employee for a period, or leave that to the payroll run — but do not sum in the
resource; if a total is wanted it comes from a service.

### Create
Commissions should be raised automatically when a booking completes, not typed. `booking_id`,
`basis_amount` and `rate` determine `amount`, and that multiplication must happen in a service —
see gap 2.

### View
**Not needed.** A commission is one line. Its context is the booking and the payroll run, both of
which have their own screens.

### Edit
Freeze everything once `payroll_item_id` is set. A commission that has been paid must not change.
Before payment, correcting the rate is legitimate.

### Relations
None. Commissions appear as lines on the employee ([`27-employee.md`](27-employee.md)) and on the
payroll run ([`30-payroll-run.md`](30-payroll-run.md)).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| _(none)_ | row | — | — | — | no row actions — gap 6 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 3 |
| _needed_ | row | `payroll_item_id IS NULL` | payroll permission | — | add Edit; freeze once paid |

## Gaps and risks

1. **🔴 No `canAccess()`.** Commission amounts are pay. Any staff role can read every agent's
   earnings and create commissions — including for themselves. Needs the payroll permission from
   [`27-employee.md`](27-employee.md) gap 1.
2. **🟡 `amount` may be hand-entered.** With `basis_amount` and `rate` both stored, `amount` should
   be `basis_amount × rate` computed in a service. If the form accepts all three independently,
   nothing prevents an amount that does not match its own basis and rate — and because money here
   is cast `'decimal:2'` rather than through `MoneyCast` (see [`README.md`](README.md) finding 12),
   that multiplication would be float arithmetic if done naively. Worth checking which happens.
3. **🔴 `DeleteBulkAction`** on commissions that may already be paid through payroll.
4. **🟡 No filters at all**, including no unpaid sweep queue.
5. **🟡 Nothing frozen after payment** — see Edit.
6. **🟡 No row actions** — same discoverability pattern as [`16-extra.md`](16-extra.md) gap 1.
7. **🔵 No automatic raising** on booking completion — see Create. Whether that is intended or
   simply unbuilt should be settled against ADV-07.

## Checklist

- [ ] Add `canAccess()` with the payroll permission; refuse self-created commissions
- [ ] Compute `amount` from `basis_amount × rate` in a service, not the form
- [ ] Remove `DeleteBulkAction`
- [ ] Add unpaid / employee / `earned_on` filters
- [ ] Freeze the row once `payroll_item_id` is set
- [ ] Add explicit row actions
- [ ] Establish whether commissions should be raised automatically on booking completion

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
```

By hand: create a commission, confirm `amount` matches basis × rate to the centime, sweep it into
a payroll run, and confirm it can no longer be edited.
