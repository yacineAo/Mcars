# 01 — CarCategory (Fleet)

**Model:** `App\Models\CarCategory` · **Slug:** `/admin/car-categories` · **Status:** 🟡 partial

Supports **REQ-02** as reference data. See
[`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md).

## What it is for

The six or so classes the fleet is priced and searched by — Economy, Compact, SUV, Luxury,
Utility, Van. A manager opens it once when the business adds a class of vehicle, and never
again. `BookingAvailabilityService::availableCars()` filters on `car_category_id`
(`BookingAvailabilityService.php:35`), so this is the one lookup table the booking path
actually reads.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 5 columns, `->filters([])` **empty**, **no default sort** |
| create | ✅ | 5 fields, flat — correct for five fields |
| view | ❌ | correct; see below |
| edit | ✅ | same form; nothing frozen |
| row actions | ✅ | `EditAction` only, via deprecated `->actions([...])` (`:76`) |
| header / toolbar actions | 🟡 | `CreateAction` on index; `DeleteBulkAction` in a group (`:79`) |
| relation managers | ❌ | none needed |
| `canAccess()` | ❌ | absent |

`cars_count` uses `->counts('cars')` (`:68`), which is a single subquery rather than a
lookup per row. That is the right way to do it and is worth noting, because most fleet
tables get relation columns wrong.

## Should be

### Index

The one real defect is the missing default sort. `sort_order` exists solely to order these
records and nothing orders by them — add `defaultSort('sort_order')`. Then add the only
filter worth having, `TernaryFilter::make('is_active')` defaulting to active.

Six rows never need pagination or search beyond what is there. Leave the rest alone.

### Create

Five fields is right. `slug` should be derived from `name` (`live()` on name,
`afterStateUpdated` filling a slug the user may still override) rather than typed twice.

### View

**Not needed.** Five columns and a count; the index already shows everything a view page
would. The cars in a category belong on `CarResource`'s index behind a category filter,
not on a relation manager here.

### Edit

**`slug` must freeze after creation.** `CarCategorySeeder` upserts on `slug`
(`CarCategorySeeder.php:24`, `firstOrCreate(['slug' => ...])`), so renaming a slug makes
the next `migrate --seed` create a *second* category with the old slug and leave the cars
pointing at the renamed one. `name`, `description`, `sort_order` and `is_active` all stay
editable.

### Relations

**None.** `CarCategory hasMany cars` (`CarCategory.php:34`) and
`maintenance_schedules.car_category_id` points here, but neither is edited from this
screen. The count column already answers "is this category in use", which is the only
question a category page raises.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| Edit | row | always | fleet write permission | — | keep |
| Create | header | always | fleet write permission | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | remove; see gap 1 |

## Gaps and risks

1. **🔴 `DeleteBulkAction` is a hard delete that silently un-categorises the fleet.**
   `CarCategory` does **not** use `SoftDeletes` (`CarCategory.php:15` — only
   `HasAuditColumns`, `HasFactory`, `LogsActivity`), and the migration creates
   `car_categories` with no `softDeletes()` column
   (`2026_07_28_160000_create_fleet_tables.php:25-35`). Meanwhile
   `cars.car_category_id` and `maintenance_schedules.car_category_id` are both
   `nullOnDelete` (same file, `:84` and `:180`). So one bulk delete sets
   `car_category_id = NULL` on every car in those categories and on every
   category-level service template, with no undo and no warning. Remove the bulk
   action; keep a single-record `DeleteAction` hidden when `cars_count > 0`.
2. **🟡 No `canAccess()`.** Per [`../02-filament-panels.md`](../02-filament-panels.md)
   §Role → visibility matrix, Fleet is `full` for manager, `read` for accountant,
   receptionist and supervisor. Nothing here enforces that — a receptionist can rename
   and delete categories. Note the honest constraint: the live database has exactly four
   permissions (`alerts.manage`, `alerts.view_logs`, `branches.view_all`,
   `reports.view_financials`) and no Shield per-resource permissions were generated, so
   there is currently nothing for a fleet `canAccess()` to check. A fleet read/write
   permission pair has to be seeded first — see README finding 2.
3. **🟡 No default sort**, so `sort_order` does nothing.
4. **🟡 Deprecated `->actions([...])`** — README finding 3.
5. **🔵 `is_active` is written and never read.** Grepped across `app/`: no service,
   widget or availability query filters categories on `is_active`; deactivating a
   category hides nothing. Either wire it into
   `BookingAvailabilityService::availableCars()` or drop the toggle.
6. **🔵 `slug` editable after seeding.** See Edit.

## Checklist

- [ ] Remove `DeleteBulkAction`; add a single `DeleteAction` disabled while `cars_count > 0`
- [ ] Add `defaultSort('sort_order')`
- [ ] Add `TernaryFilter::make('is_active')`, defaulting to active
- [ ] Freeze `slug` on the edit form
- [ ] Derive `slug` from `name` on create
- [ ] `->actions(` → `->recordActions(`
- [ ] Add `canAccess()` once a fleet permission exists; decide read vs write
- [ ] Decide whether `is_active` filters availability, or remove it

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`FleetManagementTest` asserts `CarCategorySeeder` produces at least five rows — that must
stay green after the slug freeze.

By hand: create a category, assign a car to it, then attempt to delete the category and
confirm the car keeps its category (today it does not). Reorder two categories and confirm
the index order changes.
