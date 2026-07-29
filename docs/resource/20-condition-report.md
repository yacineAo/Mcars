# 20 — ConditionReport (Bookings)

**Model:** `App\Models\ConditionReport` · **Slug:** `/admin/condition-reports` · **Status:** 🔴 needs work

Closes **REQ-05** (check-out / check-in inspection). See
[`../tasks/phase-05-bookings-contracts.md`](../tasks/phase-05-bookings-contracts.md).

## What it is for

The state of the car at handover and at return: odometer, fuel, cleanliness, damage, photos. It
is the evidence behind every closeout charge — excess kilometres, fuel shortfall, damage
deduction from the deposit — and the only defence when a customer disputes one. `checkInWithCharges`
posts the closeout extras **when a check-in report exists**, so this record directly determines
what the customer is billed.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 5 columns; **no filters**; `defaultSort('performed_at','desc')` |
| create | ✅ | |
| view | ❌ | **absent** — the photos cannot be viewed |
| edit | ✅ | fully open, forever |
| row actions | ✅ | Edit, **Delete** |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | absent |

## Should be

### Index
Filter by `type` (out / in), by booking, by car, and by `is_clean` / has-damages. Show the car
as well as the booking reference — a fleet manager looks for a car's inspection history, not a
booking's.

### Create
Created from the booking checkout/checkin flow, not typed here. The form must make the
out/in `type` unambiguous and prevent a second report of the same type for one booking — two
check-in reports means the closeout charge basis is ambiguous.

### View
**Add one, and make it show the photos.** This is the defect that matters: the damage photos are
the evidence, and there is nowhere in the panel to look at them. A view page should show the
odometer and fuel readings side by side with the paired report (out against in), the damage
notes, and the photo gallery.

Photos are Spatie Media Library on a **private disk** (ADR-009) — the gallery must serve them
through signed/temporary URLs, never a public path.

### Edit
**Freeze once the booking is closed.** A condition report is evidence; once
`checkInWithCharges` has used it to post closeout charges, editing the odometer retroactively
changes the justification for a charge already in the ledger, with no trace. After that, only
additional notes and photos should be possible — never amending a reading.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| media (photos) | **view** | no — photos may be added | — | thumbnail, filename, uploaded at |
| the paired report | **view** | yes | — | shown side by side, not as a table |

The paired out/in comparison is the point of the screen and is better rendered as two columns of
readings than as a relation manager.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | **nothing** | — | must freeze once the booking closes — gap 3 |
| `DeleteAction` | row | always | **nothing** | — | **remove** — this is evidence, gap 2 |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 2 |
| _needed_ | row | always | — | — | no way to view the photos at all — gap 1 |

## Gaps and risks

1. **🔴 No view page, so the damage photos are unviewable.** The resource exists to hold evidence
   that cannot currently be looked at. Highest-value change here.
2. **🔴 `DeleteAction` and `DeleteBulkAction` on evidence.** A condition report that justified a
   deposit deduction or an excess-km charge must not be deletable — the charge stays in the
   append-only ledger while its justification disappears, which is exactly the position you do
   not want to be in during a customer dispute. Remove both, or restrict to reports whose
   booking is still `Draft`.
3. **🔴 Fully editable forever** — see Edit. Combined with gap 2, the evidence trail for closeout
   charges has no integrity guarantee at all.
4. **🔴 No `canAccess()`.** Any staff role can create, amend and delete inspection records.
5. **🟡 Nothing prevents two reports of the same type** for one booking — see Create.
6. **🟡 No filters**, and the car is not shown.
7. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Add a view page with the photo gallery (private disk, temporary URLs) and the out/in pair
- [ ] Remove `DeleteAction` and `DeleteBulkAction`, or restrict to `Draft` bookings
- [ ] Freeze readings once the booking is closed; allow only added notes and photos
- [ ] Add `canAccess()`
- [ ] Prevent a second report of the same `type` per booking
- [ ] Add `type` / booking / car / damages filters; show the car
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CloseoutPricingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
```

`CloseoutPricingTest` covers charges derived from these readings — it must stay green.

By hand: check a car out and back in with a higher odometer and lower fuel, confirm the closeout
charges post, then confirm the report that justified them cannot be edited or deleted. Confirm a
damage photo is viewable only through a signed URL and 404s without one.
