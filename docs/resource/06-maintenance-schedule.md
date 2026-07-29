# 06 — MaintenanceSchedule (Fleet)

**Model:** `App\Models\MaintenanceSchedule` · **Slug:** `/admin/maintenance-schedules` ·
**Status:** 🔴 needs work

Serves **REQ-12** (next service due, with automatic alerts) together with
[`07-maintenance-log.md`](07-maintenance-log.md). See
[`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md) and
[`../tasks/phase-08-notifications.md`](../tasks/phase-08-notifications.md).

## What it is for

Service-interval templates: *this car needs an oil change every 10,000 km or 12 months,
whichever comes first*. [`../02-filament-panels.md`](../02-filament-panels.md)`:28` calls it
exactly that — "Service interval templates". A maintenance officer sets one up per car and per
task, and thereafter the rows are read by machinery rather than by people:
`MaintenanceDueDetector` turns `next_due_at` / `next_due_odometer` into the Phase 8
service-due alert (`app/Services/Notification/Detectors/MaintenanceDueDetector.php`).

Which makes the accuracy of those two columns the entire value of the screen.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 7 columns, `->filters([])` **empty**, no default sort |
| create | ✅ | 12 fields, flat |
| view | ❌ | correct; see below |
| edit | ✅ | same form |
| row actions | ✅ | `log_maintenance`, Edit — via deprecated `->actions([...])` (`:110`) |
| header / toolbar actions | 🟡 | `CreateAction`; `DeleteBulkAction` in a group (`:135`) |
| relation managers | ❌ | none — but this table belongs *under* a car; see Relations |
| `canAccess()` | ❌ | absent |

**Confirmed as asked: this resource does none of the due-date or odometer arithmetic itself.**
`next_due_at` is a plain `DatePicker` (`:67`), `next_due_odometer` a plain `TextInput` (`:68`),
and `last_done_at` / `last_done_odometer` likewise (`:63-66`). No interval is added to anything
anywhere in the file. That is the correct division of labour under ADR-013.

The problem is what happens on the other side of that division. See gap 1.

Two fields here *are* read by Phase 8 and are worth knowing about: `alert_km_before` and
`alert_days_before` act as a per-schedule floor under the alert rule's lead time —
`GREATEST(?, COALESCE(alert_days_before, 0))` and the matching odometer expression
(`MaintenanceDueDetector.php:44-56`). A schedule set to warn 2,000 km out keeps doing so under a
500 km rule.

## Should be

### Index

Columns: `car.registration_number` as **Car**, `carCategory.name` (the column is missing
entirely and half the table can be category-level), `task_type` as a **badge**, interval as one
column reading "10 000 km / 12 months", `next_due_at`, **days remaining**, `next_due_odometer`,
**km remaining** (car odometer minus next due, so the reader sees the same number the detector
does), `is_active` icon. Eager-load `car` and `carCategory`.

Filters:

- **Due or overdue** — the operational filter, matching the detector's own predicate so the
  screen and the alerts cannot disagree.
- `SelectFilter::make('task_type')` from `MaintenanceType`.
- `TernaryFilter::make('is_active')`, defaulted to active.
- `SelectFilter::make('car_id')` and `car_category_id`, searchable.
- **Template scope** — per-car versus per-category, since the two behave completely differently
  (gap 2).

`defaultSort('next_due_at')` ascending, nulls last. A schedule with no due date is the one that
needs attention, so surface it rather than hide it.

### Create

1. **Applies to** — `car_id` **or** `car_category_id`, exactly one of the two, enforced
   (`live()` on a radio choosing which). Today both are nullable and independent
   (`2026_07_28_160000_create_fleet_tables.php:179-180`), so a schedule attached to neither is
   savable and inert.
2. **Interval** — `interval_km`, `interval_days`. **At least one required.** A schedule with
   neither can never be recomputed and never becomes due; nothing stops one today.
3. **Alert lead time** — `alert_km_before`, `alert_days_before`, with helper text saying they
   raise the floor under the alert rule rather than replacing it.
4. **Last done / next due** — `last_done_at`, `last_done_odometer`, `next_due_at`,
   `next_due_odometer`. These should be **read-only after the first service**, computed by
   `MaintenanceSchedulerService`, and hand-editable only to seed a schedule for a car whose
   history predates the system. Label them that way.
5. **Status** — `is_active`.

### View

**Not needed.** A twelve-field template with no history of its own; the index carries the two
numbers anyone opens it for. If a schedule needs a service history, that history is the car's,
and it belongs on [`02-car.md`](02-car.md)'s Maintenance tab.

### Edit

`car_id` / `car_category_id` and `task_type` should freeze after creation.
`MaintenanceSchedulerService::recomputeSchedule()` matches schedules to logs on
`car_id` **and** `task_type` (`MaintenanceSchedulerService.php:16-20`), so retyping a schedule
from "oil change" to "tyre change" silently re-points it at a different service history and
carries the old `last_done_*` values with it.

Intervals and alert lead times stay editable — they are exactly the thing a maintenance officer
tunes.

### Relations

**None on this screen.** A schedule is a leaf: it points at one car or one category and nothing
points back at it except the alert subject.

The relation that matters runs the other way, and it is missing: **`maintenanceSchedules` belongs
on `CarResource`'s edit page**, alongside the existing `MaintenanceLogsRelationManager`. A
maintenance officer looking at a car wants "what is due" next to "what was done"; today those
two are on different screens and only one of them is reachable from the car. Columns there: task
type, interval, next due at, next due odometer, active. Editable in place, not read-only — the
intervals are maintained, not history. See [`02-car.md`](02-car.md) §Relations.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `log_maintenance` | row | `car_id` present | maintenance write | **a service** | writes the log inline; gaps 3 and 4 |
| Edit | row | always | maintenance write | — | keep |
| Create | header | always | maintenance write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 7 |

## Gaps and risks

1. **🔴 Nothing ever recomputes `next_due_at` or `next_due_odometer`, so REQ-12's "next service
   due, with automatic alerts" runs on hand-typed numbers.**
   `MaintenanceSchedulerService::recomputeSchedule()` exists and does the right arithmetic —
   `last_done_at + interval_days`, `odometer_at_service + interval_km`
   (`MaintenanceSchedulerService.php:27-47`) — and grepping the entire repository outside
   `vendor/` finds **no caller at all**: three hits, all inside its own file. There is no
   `MaintenanceLog` observer (`AppServiceProvider.php:34` registers only
   `CarDocumentObserver`), and `MaintenanceLogResource`'s `complete_service` action does not
   call it (`MaintenanceLogResource.php:141-168`). `docs/tasks/phase-02-fleet.md:62` records
   *"Next-service-due recomputes when a maintenance log completes"* as a shipped test; there is
   no such test in `tests/Feature/FleetManagementTest.php` — its fourteen cases cover
   transitions, the agreement `EXCLUDE` constraint and the document-expiry mirror, and none
   mentions `MaintenanceSchedulerService` or `next_due`.

   **Consequence:** after a service is completed, the schedule still says the car is due, so
   `MaintenanceDueDetector` keeps raising the same alert every hour until someone edits the
   schedule by hand. Phase 8's deduplication (ADR-012) will hold the window closed for a while
   and then reopen it — the alert is not wrong, the data it reads is stale. Wire
   `recomputeSchedule()` into the completion path and add the test the phase doc claims exists.
2. **🔴 Category-level schedules are invisible to the alert that is meant to fire them.**
   `car_id` is nullable and `car_category_id` exists precisely so one template can cover a class
   of vehicle (`2026_07_28_160000_create_fleet_tables.php:179-180`). But
   `MaintenanceDueDetector::detect()` opens with `->with('car')->where('is_active', true)->whereHas('car')`
   (`MaintenanceDueDetector.php:36-39`), so any schedule with a null `car_id` is filtered out
   before the due predicate is evaluated. And `log_maintenance` is hidden for those same rows
   (`MaintenanceScheduleResource.php:131`). So a category template alerts nobody and offers no
   action — it is a row that does nothing. Either expand the schedule to the cars in its category
   in the detector, or drop `car_category_id` and require `car_id`. Do not leave both halves half
   built.
3. **🔴 `log_maintenance` creates a `MaintenanceLog` from the resource, with no guard.**
   `MaintenanceScheduleResource.php:119-124` calls `MaintenanceLog::create()` directly. It sets
   four fields, checks nothing, and clicking the button twice creates two scheduled logs for the
   same service — which then both appear as due work and both, on completion, would recompute
   the same schedule. Move it to a service that refuses when an open (`scheduled` /
   `in_progress`) log already exists for that car and task type.
4. **🟡 `log_maintenance` puts the log in the wrong branch.** It passes no `branch_id`, so
   `BelongsToBranch` fills it from the **authenticated user's** home branch
   (`BelongsToBranch::resolveBranchId()`, `app/Models/Concerns/BelongsToBranch.php:82-100`) —
   not from the car's. A manager at Algiers scheduling work on an Oran car files the log against
   Algiers, and once Phase 10's scope is switched on that log disappears from the branch that
   owns the car. Pass `$record->car->branch_id` explicitly, in the service.
5. **🟡 A schedule can be saved that can never become due.** Neither `interval_km` nor
   `interval_days` is required, and neither `next_due_at` nor `next_due_odometer` is. Four nulls
   is a valid row. See Create.
6. **🟡 Nothing prevents duplicate schedules.** No unique index on `(car_id, task_type)` in the
   migration, and no form check. Five "oil change" schedules for one car produce five alerts, and
   `recomputeSchedule()` would update all five from the same log
   (`MaintenanceSchedulerService.php:16-25` iterates every match). Add the unique index —
   partial, since `car_id` is nullable.
7. **🟡 `DeleteBulkAction` is a hard delete.** `MaintenanceSchedule` does not use `SoftDeletes`
   (`MaintenanceSchedule.php:13-15`) and the migration creates no `deleted_at`
   (`2026_07_28_160000_create_fleet_tables.php:177-194`). Losing a template is recoverable by
   retyping it, so this is milder than the equivalent on
   [`01-car-category.md`](01-car-category.md) — but it also silently removes a car from the
   service-due alert set, which nobody will notice. Prefer deactivating (`is_active = false`) to
   deleting, and drop the bulk action.
8. **🟡 Empty `->filters([])`, no default sort, no category column**, on a table whose only
   purpose is answering "what is due".
9. **🟡 N+1 on the index.** `car.registration_number` (`:88`) is a lookup per row;
   `ListMaintenanceSchedules` is a bare stub.
10. **🟡 No `canAccess()`.** This is the one Fleet resource where the role matrix is explicit
    about a *narrower* role having *more* access:
    [`../02-filament-panels.md`](../02-filament-panels.md)`:97` gives the maintenance officer
    "full (maintenance), read (rest)". Nothing here distinguishes them. Same blocker as the rest
    of the cluster — four permissions exist in the live database, no Shield per-resource
    permissions (README finding 2) — so a `maintenance.manage` permission has to be seeded
    before this can be enforced.
11. **🟡 Deprecated `->actions([...])`** — README finding 3.
12. **🔵 No `branch_id` on `maintenance_schedules`.** Unlike `maintenance_logs`, this table has
    none and the model does not use `BelongsToBranch`. The detector reaches branch through
    `car.branch_id` (`MaintenanceDueDetector.php:58-70`), which works, but Phase 10's branch
    scope will have to do the same rather than scope the table directly. Note it now.
13. **🔵 Action label does not translate.** "Log Service Now" is set with `->label()` and never
    `->translateLabel()`, while `lang/fr.json` already carries the French. See
    [`03-car-owner.md`](03-car-owner.md) gap 10 — one panel-level fix.

## Checklist

- [ ] Call `MaintenanceSchedulerService::recomputeSchedule()` when a maintenance log completes,
      and add the test `docs/tasks/phase-02-fleet.md:62` already claims
- [ ] Decide category-level schedules: expand them in `MaintenanceDueDetector`, or drop
      `car_category_id` and require `car_id`
- [ ] Move `log_maintenance` into a service that refuses a duplicate open log and sets
      `branch_id` from the car
- [ ] Require exactly one of `car_id` / `car_category_id`, and at least one interval
- [ ] Add a partial unique index on `(car_id, task_type)`
- [ ] Add the due/overdue, task-type, active, car, category and scope filters;
      `defaultSort('next_due_at')` nulls last
- [ ] Add the category column, days-remaining and km-remaining columns; badge `task_type`
- [ ] Eager-load `car` and `carCategory`
- [ ] Freeze `car_id` / `car_category_id` and `task_type` on edit; make `next_due_*` read-only
      once a service has been logged
- [ ] Attach `maintenanceSchedules` as a relation manager on `CarResource`'s edit page
- [ ] Replace `DeleteBulkAction` with deactivation
- [ ] `->actions(` → `->recordActions(`
- [ ] Add `canAccess()` once a maintenance permission exists

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`Phase8Test` covers `MaintenanceDueDetector`; if category-level schedules are made detectable,
that is where the new case belongs. The recompute test is new and belongs in
`FleetManagementTest` alongside the document-expiry mirror tests, which are the closest
existing analogue.

By hand: create a schedule with a 10,000 km interval and `last_done_odometer` 20,000, complete a
maintenance log for that car at 30,000 km, and re-read the schedule. Today `next_due_odometer`
does not move; it must become 40,000. Then run `php artisan alerts:evaluate` twice and confirm
the service-due alert stops firing once the service is recorded.
