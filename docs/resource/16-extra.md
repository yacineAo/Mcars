# 16 — Extra (Bookings)

**Model:** `App\Models\Extra` · **Slug:** `/admin/extras` · **Status:** ✅ done

Supports **REQ-05** (booking add-ons). Small master-data screen.

## What it is for

The paid add-ons offered with a rental: child seat, GPS, extra driver, delivery. Six fields,
set up once, changed rarely. Each maps to a ledger account so that selling one posts revenue to
the right place.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | sorted by name; filters: `is_active` (defaults to true), `pricing_unit` |
| create | ✅ | name, code (unique), pricing unit, unit price, ledger account (restricted to postable revenue-type accounts), active |
| view | ✅ | added — catalogue fields plus a derived "times sold" count |
| edit | ✅ | `code` frozen once the extra has been sold; `unit_price` stays editable |
| row actions | ✅ | Edit; Delete hidden while the extra has ever been sold |
| header / toolbar actions | ✅ | `CreateAction` only; bulk actions removed |
| relation managers | ❌ | none — not needed |
| `canAccess()` | ✅ | `bookings.view` / `bookings.manage` permissions |

## Gaps and risks (resolved)

| # | Gap | Resolution |
|---|---|---|
| 1 | No row actions whatsoever | Added `EditAction`; `DeleteAction` hidden via `->hidden(...)` once `booking_extras_count > 0`, and `canDelete()` refuses a sold extra server-side. Deactivation via `is_active` is the alternative for sold extras. |
| 2 | No `canAccess()` | Added. New permission pair seeded: `bookings.view` (super_admin, manager, accountant, receptionist, supervisor — every role on the Bookings row of the visibility matrix except the maintenance officer, whose read is blocks only) and `bookings.manage` (super_admin, manager). `canCreate`/`canEdit`/`canDelete` gate on `bookings.manage`. |
| 3 | No filters or sort | Added `is_active` (defaults to true — inactive extras stay out of the quoting list) and `pricing_unit` filters; default sort by name. |
| 4 | `ledger_account_id` unrestricted | Restricted to `is_postable` revenue-type accounts via the relationship callback — an extra must credit a revenue account, not an expense one. |
| 5 | `code` editable after sale | Frozen once `hasBeenSold()`, with a helper text explaining why. The `code` is a stable identifier `BookingExtra` rows reference. |
| 6 | `unit_price` revaluing history? | **Verified safe.** `booking_extras` stores its own `unit_price` and `total` at the time of sale, so changing the catalogue price never touches past bookings (asserted by `ExtraResourceTest`). |

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ExtraResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/CloseoutPricingTest.php
```

By hand: change an extra's price and confirm a past booking's total does **not** move.
