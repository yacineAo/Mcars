# 16 — Extra (Bookings)

**Model:** `App\Models\Extra` · **Slug:** `/admin/extras` · **Status:** 🟡 partial

Supports **REQ-05** (booking add-ons). Small master-data screen.

## What it is for

The paid add-ons offered with a rental: child seat, GPS, extra driver, delivery. Six fields,
set up once, changed rarely. Each maps to a ledger account so that selling one posts revenue to
the right place.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 5 columns, **no filters, no sort, no actions** |
| create | ✅ | name, code (unique), pricing unit, unit price, ledger account, active |
| view | ❌ | not needed |
| edit | ✅ | reachable, but only by clicking the row |
| row actions | ❌ | **none at all** |
| header / toolbar actions | 🟡 | `CreateAction` only; no bulk actions (which is fine) |
| relation managers | ❌ | none needed |
| `canAccess()` | ❌ | absent |

## Should be

### Index
Add an `is_active` filter and a `pricing_unit` filter, and sort by name. Five rows today, but
this is the list a receptionist scans while quoting, so inactive extras should be filterable out
rather than shown alongside live ones.

### Create
Fine as it stands. `ledger_account_id` should be restricted to postable revenue accounts —
pointing an extra at an expense account produces a posting that balances and means nothing.

### View
**Not needed.** Six fields with no history. The booking-extras usage question ("how often do we
sell child seats") is a report, not a screen.

### Edit
`code` should freeze once the extra has been sold — it is a stable identifier and
`BookingExtra` rows reference the extra. `unit_price` must stay editable (prices change), and
that is safe because `BookingExtra` stores its own `unit_price` at the time of sale — worth
confirming, because if it does not, changing the price here would retroactively rewrite the
value of every past booking.

### Relations
None. The `bookingExtras` usage history is report territory, not a relation manager on master
data.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| _(none)_ | row | — | — | — | **no row actions at all** — gap 1 |
| `CreateAction` | header | always | **nothing** | — | only action on the screen |
| _needed_ | row | always | new gate | — | add `EditAction`; delete guarded on never-sold |

## Gaps and risks

1. **🟡 No row actions whatsoever.** The table defines only columns — no `EditAction`, no
   `DeleteAction`, no `recordActions` at all. Rows are still reachable, because
   `ListRecords` installs a default record URL to the edit page
   (`vendor/filament/filament/src/Resources/Pages/ListRecords.php:162`), so this is a
   discoverability problem rather than a dead screen: there is no visible affordance and **no
   delete or deactivate path from the list**. Add an explicit `EditAction`, and a delete guarded
   on the extra never having been sold — otherwise use `is_active`.
2. **🟡 No `canAccess()`.** Editing a price here changes what customers are charged.
3. **🟡 No filters or sort** — see Index.
4. **🟡 `ledger_account_id` unrestricted** — see Create.
5. **🔵 Verify `BookingExtra` snapshots `unit_price`.** If it does not, this screen can silently
   revalue historical bookings, which would also mean the ledger and the booking disagree. This
   is the one thing on an otherwise low-risk screen that could matter a lot.

## Checklist

- [ ] Add explicit `EditAction`; add delete guarded on never-sold, else prefer `is_active`
- [ ] Add `canAccess()`
- [ ] Add `is_active` / `pricing_unit` filters and a default sort
- [ ] Restrict `ledger_account_id` to postable revenue accounts
- [ ] Freeze `code` once the extra has been sold
- [ ] Confirm `BookingExtra` stores its own `unit_price` at time of sale

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/CloseoutPricingTest.php
```

By hand: change an extra's price and confirm a past booking's total does **not** move.
