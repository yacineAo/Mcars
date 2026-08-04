# 01 — CarCategory (Fleet)

**Model:** `App\Models\CarCategory` · **Slug:** `/admin/car-categories` · **Status:** ✅ complete

Reference data for **REQ-02**. See [`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md).

## What it is for

The six classes the fleet is priced and searched by — Economy, Compact, SUV, Luxury, Utility,
Van. A manager opens it when the business adds a class of vehicle and never again.
`BookingAvailabilityService::availableCars()` filters on `car_category_id`
(`BookingAvailabilityService.php:35`), so it is the one lookup table the booking path reads.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 6 columns, `defaultSort('sort_order')`, `TernaryFilter::make('is_active')->default(true)` |
| create | ✅ | `slug` derived from `name` via `->afterStateUpdated()` |
| view | ✅ | added — name/slug/description/sort_order/cars_count, no relation manager (the index already answers "is this in use") |
| edit | ✅ | `slug` frozen (`->disabled()` on edit) |
| row actions | ✅ | `EditAction` + `DeleteAction` hidden when `cars_count > 0`, via `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` only; `DeleteBulkAction` removed |
| relation managers | ❌ | none needed |
| `canAccess()` | ✅ | `fleet.view` / `fleet.manage` |

`cars_count` uses `->counts('cars')` — one subquery, not a lookup per row.

## Should be

### Index
Add `defaultSort('sort_order')` — the column exists solely to order these records and nothing
orders by them. Add `TernaryFilter::make('is_active')`, defaulted to active. Six rows need
nothing else.

### Create
Derive `slug` from `name` rather than typing it twice.

### View
**Added.** Five columns and a count; the index already showed everything, but a view page is
now standard across resources. The cars in a category still belong on `CarResource`'s index
behind a category filter — no relation manager was added here.

### Edit
`slug` must **freeze**. `CarCategorySeeder` upserts on it (`CarCategorySeeder.php:24`,
`firstOrCreate(['slug' => ...])`), so renaming a slug makes the next `migrate --seed` create a
second category with the old slug and leave the cars pointing at the renamed one.

### Relations
**None.** `CarCategory hasMany cars` (`CarCategory.php:34`) and
`maintenance_schedules.car_category_id` points here, but neither is edited from this screen; the
`cars_count` column already answers "is this in use".

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | **nothing** | — | keep; freeze `slug` |
| `CreateAction` | header | always | **nothing** | — | keep |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — hard delete, gap 1 |
| _needed_ | row | `cars_count === 0` | fleet permission | — | single `DeleteAction`, hidden while in use |

## Gaps and risks

1. ~~**🔴 `DeleteBulkAction` is a hard delete that silently un-categorises the fleet.**
   `CarCategory` does not use `SoftDeletes`...~~ → **Resolved.** Removed. Single row `DeleteAction`
   hidden while `cars_count > 0`.
2. ~~**🟡 No `canAccess()`.**~~ → **Resolved.** `fleet.view` / `fleet.manage` permissions seeded;
   `canAccess()`, `canCreate()`, `canEdit()`, `canDelete()` added.
3. ~~**🟡 No default sort**...~~ → **Resolved.** `defaultSort('sort_order')`.
4. ~~**🟡 Deprecated `->actions([...])`**~~ → **Resolved.** Uses `->recordActions([...])`.
5. **🔵 `is_active` is written and never read.** Grepped `app/`: no service, widget or
   availability query filters categories on it — `availableCars()` filters status, category and
   branch only (`BookingAvailabilityService.php:32-41`). Deactivating a category hides nothing.
   The `TernaryFilter` on the index gives staff manual control but the column is not wired into
   availability. Wired in or removed in Phase 9 if a business requirement emerges.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`FleetManagementTest` asserts `CarCategorySeeder` produces at least five rows — that must stay
green after the slug freeze.

By hand: create a category, assign a car to it, then try to delete the category and confirm the
car keeps its category (today it does not). Reorder two categories and confirm the index order
changes.
