# 01 — CarCategory (Fleet)

**Model:** `App\Models\CarCategory` · **Slug:** `/admin/car-categories` · **Status:** 🟡 partial

Reference data for **REQ-02**. See [`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md).

## What it is for

The six classes the fleet is priced and searched by — Economy, Compact, SUV, Luxury, Utility,
Van. A manager opens it when the business adds a class of vehicle and never again.
`BookingAvailabilityService::availableCars()` filters on `car_category_id`
(`BookingAvailabilityService.php:35`), so it is the one lookup table the booking path reads.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 5 columns, `->filters([])` **empty**, **no default sort** |
| create | ✅ | 5 fields, flat — correct at this size |
| view | ❌ | correct, see below |
| edit | ✅ | same form; nothing frozen |
| row actions | ✅ | `EditAction` only, via deprecated `->actions([...])` (`:76`) |
| header / toolbar actions | 🟡 | `CreateAction`; `DeleteBulkAction` in a group (`:79`) |
| relation managers | ❌ | none needed |
| `canAccess()` | ❌ | absent |

`cars_count` uses `->counts('cars')` (`:68`) — one subquery, not a lookup per row. Right, and
worth noting because most Fleet tables get relation columns wrong.

## Should be

### Index
Add `defaultSort('sort_order')` — the column exists solely to order these records and nothing
orders by them. Add `TernaryFilter::make('is_active')`, defaulted to active. Six rows need
nothing else.

### Create
Derive `slug` from `name` rather than typing it twice.

### View
**Not needed** — five columns and a count; the index already shows everything. The cars in a
category belong on `CarResource`'s index behind a category filter.

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

1. **🔴 `DeleteBulkAction` is a hard delete that silently un-categorises the fleet.**
   `CarCategory` does not use `SoftDeletes` (`CarCategory.php:15` — only `HasAuditColumns`,
   `HasFactory`, `LogsActivity`) and the migration creates no `deleted_at`
   (`2026_07_28_160000_create_fleet_tables.php:25-35`). Meanwhile `cars.car_category_id` and
   `maintenance_schedules.car_category_id` are both `nullOnDelete` (same file, `:84` and
   `:180`). One bulk delete therefore sets `car_category_id = NULL` on every affected car and
   every category-level service template, with no undo and no warning.
2. **🟡 No `canAccess()`.** [`../02-filament-panels.md`](../02-filament-panels.md) §Role →
   visibility matrix makes Fleet `full` for manager and `read` for accountant, receptionist and
   supervisor; nothing enforces it, so a receptionist can rename and delete categories. Honest
   blocker: the live database holds exactly four permissions (`alerts.manage`,
   `alerts.view_logs`, `branches.view_all`, `reports.view_financials`) and no Shield
   per-resource permissions were generated, so a fleet read/write pair must be seeded before
   `canAccess()` has anything to check. README finding 2.
3. **🟡 No default sort**, so `sort_order` does nothing.
4. **🟡 Deprecated `->actions([...])`** — README finding 3.
5. **🔵 `is_active` is written and never read.** Grepped `app/`: no service, widget or
   availability query filters categories on it — `availableCars()` filters status, category and
   branch only (`BookingAvailabilityService.php:32-41`). Deactivating a category hides nothing.
   Wire it in or drop the toggle.

## Checklist

- [ ] Remove `DeleteBulkAction`; add a single `DeleteAction` disabled while `cars_count > 0`
- [ ] Add `defaultSort('sort_order')`
- [ ] Add `TernaryFilter::make('is_active')`, defaulted to active
- [ ] Freeze `slug` on edit; derive it from `name` on create
- [ ] `->actions(` → `->recordActions(`
- [ ] Add `canAccess()` once a fleet permission exists
- [ ] Decide whether `is_active` filters availability, or remove it

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
