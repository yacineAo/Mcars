# 21 — CarBlock (Bookings)

**Model:** `App\Models\CarBlock` · **Slug:** `/admin/car-blocks` · **Status:** ✅ audited — fine

Supports **REQ-05** (availability). See
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-002.

## What it is for

Taking a car off the market for a window that is not a rental: workshop time, an owner using
their own car, a long-term reservation held by hand. Blocks participate in availability, so a
block and a booking must not be able to overlap on the same car.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | state / car / reason / window filters; `defaultSort('starts_at','desc')` |
| create | ✅ | via `CarBlockService`; clashes surface as field errors |
| view | ❌ | not needed |
| edit | ✅ | `car_id` frozen; re-checks clashes on save |
| row actions | ✅ | `unblock`/`cancel`, Edit, Delete |
| header / toolbar actions | ✅ | `CreateAction` only |
| relation managers | ❌ | none needed |
| `canAccess()` | ✅ | `bookings.view` OR `fleet.manage_maintenance` |

## Invariants

A block takes a car off the market for a half-open window `[starts_at, ends_at)`:

- **No overlap with another block on the same car** — Postgres `EXCLUDE` constraint
  (`car_blocks_no_overlap`, ADR-002) is the race-proof backstop.
- **No overlap with a confirmed/active/overdue booking on the same car** — the
  `check_car_block_booking_conflict` trigger enforces it on writes.
- **`ends_at > starts_at`** — a `CHECK` constraint; the form also validates it first.
- Every write (create, update, `endEarly`) goes through **`CarBlockService`**, which mirrors
  the DB guards in readable form so the user sees "the car is already blocked during part of
  this window" instead of a raw constraint error. The DB stays the backstop, not the teacher.
- **`endEarly`** truncates an in-force block to now — recorded in the activity log, so how
  long the car was actually off the road stays answerable.
- **`cancel`** deletes a block that has not started yet. Deleting a future block is not losing
  evidence — it never took a car off the road; ending it would leave an inverted window that
  records nothing.

## Access

- Read: `bookings.view` or `fleet.manage_maintenance` — the maintenance officer's
  visibility-matrix row on Bookings is scoped to blocks only (the workshop's list).
- Write (create/edit/delete): `bookings.operate` — deciding a car is off the market is the
  desk's call; an accountant audits.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CarBlockResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
```

The Phase 5 concurrency test must still hold: two overlapping bookings in parallel transactions,
exactly one commits. A block overlapping a booking must be refused by the same mechanism.

By hand: block a car, attempt to book it inside the window and confirm a readable refusal.
Create a block starting next week, press Unblock, and confirm you do not end up with
`ends_at < starts_at`.
