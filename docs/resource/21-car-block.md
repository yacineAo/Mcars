# 21 — CarBlock (Bookings)

**Model:** `App\Models\CarBlock` · **Slug:** `/admin/car-blocks` · **Status:** 🟡 partial

Supports **REQ-05** (availability). See
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-002.

## What it is for

Taking a car off the market for a window that is not a rental: workshop time, an owner using
their own car, a long-term reservation held by hand. Blocks participate in availability, so a
block and a booking must not be able to overlap on the same car.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | no filters |
| create | ✅ | |
| view | ❌ | not needed |
| edit | ✅ | |
| row actions | ✅ | `unblock`, Edit, Delete |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none needed |
| `canAccess()` | ❌ | absent |

## Should be

### Index
Filter by **active now** (`starts_at <= now < ends_at`), by car, by reason, and by a date range.
"Which cars are off the road today" is the question this screen answers and it currently cannot
be asked. Show the car, reason, the window, and whether it is currently in force.

Sort by `starts_at desc`.

### Create
`starts_at` / `ends_at` must be validated against existing blocks **and bookings** for the same
car. Availability is enforced by the Postgres `EXCLUDE` constraint plus
`BookingAvailabilityService` (ADR-002) — so the form should surface the clash as a readable
validation error rather than letting the constraint throw a raw database error at the user.
Worth verifying which currently happens.

### View
**Not needed.** A block is a car, a window and a reason. The car's view page is the right place
to see its blocks in context — see the Fleet audit for `CarResource`.

### Edit
Shortening a block is fine; extending it must re-check for clashes, because the window may now
overlap a booking made in the meantime. Freeze `car_id` — moving a block to a different car is
a delete-and-recreate, not an edit, since it changes what was validated.

### Relations
None. A block belongs on the car, not the reverse.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `unblock` | row | `ends_at > now()` | **nothing** | raw `update(['ends_at' => now()])` | rewrites history; breaks for future blocks — gaps 1, 6 |
| `EditAction` | row | always | **nothing** | — | re-check clashes when extending |
| `DeleteAction` | row | always | **nothing** | — | prefer `unblock` |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 3 |

## Gaps and risks

1. **🔴 `unblock` writes `ends_at = now()` inline** (`CarBlockResource.php:71-72`). It is a raw
   `$record->update(['ends_at' => now()])` in a Filament closure. Two problems beyond the
   layering point: it silently rewrites the historical record of how long the car was actually
   blocked, and it bypasses any check that the shortened window is consistent. Ending a block
   early is a legitimate operation — it should go through a service that records it, so
   "this car was blocked 3 days, released early on the 2nd" stays answerable.
2. **🟡 No `canAccess()`.** Blocking a car removes it from sale; unblocking puts it back. Not
   every role should decide that.
3. **🟡 `DeleteBulkAction`** — deleting blocks in bulk silently returns cars to availability
   with no record. Prefer `unblock`.
4. **🟡 No filters**, so the current state of the fleet is not visible here — see Index.
5. **🟡 Clash validation unverified** — see Create. If the `EXCLUDE` constraint is the only guard,
   the user sees a database error rather than an explanation.
6. **🟡 `unblock` visible test uses `$record->ends_at > now()`** — a block that has not started
   yet is therefore "unblockable", which sets `ends_at` before `starts_at` and leaves an
   inverted window. Cancelling a future block should delete it, not end it.
7. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Move `unblock` into a service that records the early release rather than rewriting `ends_at`
- [ ] Fix the inverted-window case for a block that has not started (cancel, don't end)
- [ ] Add `canAccess()`
- [ ] Remove `DeleteBulkAction`; prefer `unblock`
- [ ] Add active-now / car / reason / date-range filters; show whether in force
- [ ] Surface booking and block clashes as validation, not a database error
- [ ] Freeze `car_id` on edit; re-check clashes when extending
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
```

The Phase 5 concurrency test must still hold: two overlapping bookings in parallel transactions,
exactly one commits. A block overlapping a booking must be refused by the same mechanism.

By hand: block a car, attempt to book it inside the window and confirm a readable refusal.
Create a block starting next week, press Unblock, and confirm you do not end up with
`ends_at < starts_at`.
