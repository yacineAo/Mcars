# 02 — Car (Fleet)

**Model:** `App\Models\Car` · **Slug:** `/admin/cars` · **Status:** ✅ done

Closes **REQ-02** (the car page), feeds **REQ-11** (per-car profitability) and **ADV-02**
(document archive). See [`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md),
[`../02-filament-panels.md`](../02-filament-panels.md) §Car page, and
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-006, ADR-009, ADR-013.

## What it is for

The centrepiece of the panel. A receptionist opens the index to find a car that is free and
send it out; a maintenance officer opens one car to see what has been done to it and what is
due; a manager opens the same car to see whether it earns its keep. REQ-02 states the
requirement precisely: one page per car carrying identity, photos, odometer, status,
document expiry, **full contract history, total profit, total expenses, total rental days
and utilisation rate**.

Three of those five history figures exist. None of the history tables do.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 9 columns (incl. gated MTD profit), 6 filters, `defaultSort('registration_number')`, eager-loaded category + owner, `->recordActions([...])` |
| create | ✅ | 8 sections (Identity / Classification / Specification / Pricing / Acquisition / Telematics / Photos / Status), GPS + photo uploads, status limited to available/out_of_service |
| view | ✅ | `ViewCar` — 6 infolist sections, photos, coloured days-to-expiry, Profitability with a period picker |
| edit | ✅ | same sections as create; `chassis_number`/`registration_number`/`ownership_type` frozen when in use; `status` removed |
| row actions | ✅ | `book_now` → `BookingResource`, `update_status_odometer` → `FleetStatusService`, `block_car` → `BlockCarService`, View, Edit, Delete — via `->recordActions([...])`; service errors reported as notifications |
| header / toolbar actions | ✅ | `CreateAction` only; `DeleteBulkAction` removed |
| relation managers | ✅ | 9 of 9, grouped, split read-only (view) / editable (edit) via `getAllRelationManagers()` |
| `canAccess()` | ✅ | `fleet.view`; writes gated on `fleet.manage`, maintenance on `fleet.manage_maintenance` |

`ListCars` carries only a `CreateAction` — eager loading and the branch pin moved to
`CarResource::getEloquentQuery()`, since the deprecated `getTableQuery()` override only
covered that one page.

**What `ViewCar` gets right, verified.** The Profitability section is gated on the
permission, not a role list — `->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)`
at `ViewCar.php:87` — and the gate sits on the `Section`, so nothing inside it renders for a
receptionist. Every figure comes from `ReportService::singleCarProfitability()`
(`ViewCar.php:121`), memoised with a null check so five entries cost one report query
(`ViewCar.php:118`). The only arithmetic in the page is `number_format` in `money()`
(`ViewCar.php:134-137`) — no revenue, expense, profit, rental-day or utilisation figure is
computed locally. This is the pattern the rest of the panel should copy.

The index has **no relation columns at all**, so despite being the busiest fleet table it
carries no N+1. Also worth stating, because it is unusual here.

## Should be

### Index ✅ done

Columns, in order: `registration_number` as **Plate** (sortable, searchable), brand + model
as one searchable column, `category.name`, `status` as a **badge**, `daily_rate`, `odometer`,
`owner.first_name` (toggleable, hidden by default), `is_active` icon. Eager-loads `category`
and `owner` in `ListCars`.

`status` uses `->badge()` — `CarStatus` colours reach the screen correctly via Filament's
built-in resolution.

Filters:

- `SelectFilter::make('status')` from `CarStatus` ✅
- `SelectFilter::make('car_category_id')` relationship filter ✅
- `SelectFilter::make('ownership_type')` — company versus third-party ✅
- `TernaryFilter::make('is_active')`, defaulting to active ✅
- **Documents expiring** — `SelectFilter::make('document_status')` over the four
  `*_expiry_date` mirror columns: expired / expiring within 30 days / missing an expiry
  date ✅
- Branch filter, visible only with `branches.view_all` ✅ — and `getEloquentQuery()` pins a
  user without the permission to `accessibleBranchIds()` server-side, so a hand-crafted
  Livewire payload naming another branch returns nothing.

`defaultSort('registration_number')` ✅.

A receptionist needs plate, category, status, daily rate. A manager additionally wants
ownership type and, gated on `reports.view_financials`, a month-to-date profit column
sourced from `ReportService::singleCarProfitability()` — never a stored column, and only as
a toggleable one hidden by default, since it is a per-row report query ✅.

### Create ✅ done

Thirty-four fields now sectioned into 8 groups (see the form in `CarResource.php`):

1. **Identity** — brand, model, trim, year, colour, chassis number (VIN), engine number,
   registration number (plate), registration date.
2. **Classification** — category, ownership type, `car_owner_id` (shown only when
   `ownership_type` is third-party via `->live()` + `->hidden()`).
3. **Specification** — body type, transmission, fuel type, seats, doors.
4. **Pricing** — daily / weekly / monthly rate, security deposit, mileage limit per day,
   extra-km price, late-hour fee.
5. **Acquisition** — purchase date, purchase price, current value.
6. **Telematics** — `gps_device_id`, `gps_provider` (both were missing, now present).
7. **Photos** — `gallery` and `damage` via `SpatieMediaLibraryFileUpload`, both collections
   pinned to the **private** disk in `Car::registerMediaCollections()` (ADR-009).
8. **Status** — status (limited to available / out_of_service, `required()` because
   `cars.status` is NOT NULL), odometer, `is_active`, notes.

Correctly absent: `odometer_updated_at`, `insurance_expiry_date`,
`technical_inspection_expiry_date`, `registration_expiry_date`, `road_tax_expiry_date`.

`car_owner_id` is hidden for a company-owned car, and hiding alone cannot clear it — a hidden
Filament component is skipped during dehydration, so switching third-party → company-owned
would leave the old owner on the row. `Car::booted()` nulls it on save; that is the invariant,
the form is only the affordance.

### View ✅ done

Six infolist sections — Identity, **Photos**, Status & Specs, Pricing, Document Expiry,
Profitability — plus the read-only history tabs grouped as **Bookings** (bookings, contracts,
fines, blocks) and **Owner** (instalments).

- **Profitability now takes a period.** A `profitability_period` header action offers this
  month / this year / **lifetime** (the default, per REQ-02's "total") / a custom range.
  Lifetime starts at `purchase_date ?? created_at`, not an epoch — utilisation divides by
  calendar days in the period, so starting at 1970 would report ~0% for every car. Still one
  memoised `ReportService::singleCarProfitability()` call for all five entries.
- **Document Expiry shows days remaining, coloured**: red once expired, amber inside 30 days,
  green beyond, grey when not recorded — "expires in 4 days" rather than `2026-08-02`.

### Edit ✅ done

- `chassis_number` and `registration_number` freeze once the car has a booking or a contract
  (`->disabled()` with `$record->bookings()->exists() || $record->contracts()->exists()`).
- `ownership_type` freezes while an active `car_ownership_agreement` exists.
- `status` is removed from the edit form entirely (state machine via the row action only).
- Everything else — rates, specification, notes, telematics — stays editable.

### Relations ✅ done

`Car hasMany`: `agreements`, `documents`, `maintenanceLogs`, `maintenanceSchedules`,
`bookings`, `contracts`, `blocks`, `fines`, `ownerInstallments`. All nine are wired.

| Relation | Where | Read-only | Gate | Notes |
|---|---|---|---|---|
| `documents` | **edit** | no — the office maintains these | `fleet.manage` | + `SpatieMediaLibraryFileUpload` for the scan, + the E42 `post_renewal_cost` action |
| `maintenanceLogs` | **edit** | no — maintained in place | `fleet.manage_maintenance` | costs and `status` off the form; completion goes through `CompleteMaintenanceService` |
| `maintenanceSchedules` | **edit** | no — intervals are maintained here | `fleet.manage_maintenance` | `next_due_*` off the form; derived on completion |
| `agreements` | **edit** | no | `fleet.manage` | bulk delete removed — E32 accrues against these |
| `bookings` | **view** | **yes** | `fleet.view` | reference, customer, pickup/return, days, status, total |
| `contracts` | **view** | **yes** | `fleet.view` | number, status, generated/signed/closed at |
| `fines` | **view** | **yes** | `fleet.view` | notice, violation date, amount, liability, status |
| `blocks` | **view** | **yes** — written by `block_car` | `fleet.view` | reason, from/until, notes |
| `ownerInstallments` | **view** | **yes** | `reports.view_financials` | due date, #, amount, status |

Filament renders `getRelations()` on **both** the view and the edit page — `ViewRecord` and
`EditRecord` both use `Concerns\HasRelationManagers` — so an entry in `getRelations()` alone
puts a writable table on `ViewCar`. Both pages therefore override
`getAllRelationManagers()`, and the read-only managers additionally return `isReadOnly()`
true and refuse `canCreate`/`canEdit`/`canDelete`, so the split does not depend on which page
happened to load them.

Note for future relation managers: on a `RelationManager` these are **`protected` instance**
methods (`InteractsWithRelationshipTable`), not the `public static` ones a `Resource` uses.
Declaring them static is a fatal, not a silent no-op.

Nine tabs is too many to scan, so they are grouped with `RelationGroup` the way the panel
doc's tab list does: **Documents**, **Maintenance** (logs + schedules), **Owner** on edit;
**Bookings** (bookings + contracts + fines + blocks) and **Owner** (instalments) on view.

### Actions ✅ done

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `book_now` | row | status ∈ {available, reserved} | `fleet.view` | — (URL to `BookingResource`) | `openUrlInNewTab()` dropped |
| `update_status_odometer` | row | status not terminal | `fleet.view` | **`FleetStatusService::transition()`** | `Select` built from `allowedTransitions()`; refusals reported as a notification, not a 500 |
| `block_car` | row | status ∈ {available, reserved} | `fleet.view` | **`BlockCarService`** | validates the window; conflicts reported as a notification |
| View / Edit | row | always | `fleet.view` / `fleet.manage` | — | keep |
| Create | header | always | `fleet.manage` | — | keep |
| Delete | row | hidden when a booking exists | `fleet.manage` | — | replaces `DeleteBulkAction`; the guard reads `bookings_exists` from `withExists()`, not a per-row query |

## Gaps and risks

1. **🔴 `update_status_odometer` was bypassing the fleet state machine.**
   **Fixed.** The action now calls `FleetStatusService::transition()` and the `Select` is
   built from `allowedTransitions()`. The dead `'cancelled'` entry was removed from the
   service. The DB `CHECK` constraint would have rejected it anyway.
2. **🔴 `block_car` was writing a `CarBlock` from the resource.**
   **Fixed.** `app/Services/Booking/BlockCarService.php` validates `ends_at > starts_at`,
   checks `BookingAvailabilityService::conflictsFor()`, and creates the block.
3. **🔴 `DeleteBulkAction` on cars.**
   **Fixed.** Removed; replaced with a single `DeleteAction` hidden when bookings exist.
4. **🔴 REQ-02 asks for photos and there is no way to attach one.**
   **Fixed.** `filament/spatie-laravel-media-library-plugin` is installed and the `media`
   table migrated. `gallery` and `damage` are on the car form and rendered on `ViewCar` via
   `SpatieMediaLibraryImageEntry`; `CarDocument`'s `document` collection is on
   `DocumentsRelationManager`. All three collections are pinned to the **private** disk —
   `Car::registerMediaCollections()` previously defaulted to `public`, which ADR-009 forbids.
   [`05-car-document.md`](05-car-document.md) gap 1 (serving the file back) is still open.
5. **🔴 The Profitability section's expense figure is structurally understated.**
   **Fixed.** `app/Services/Accounting/MaintenancePoster.php` now builds **E41** (maintenance
   completed → Dr 5040, Cr 1010/1020 or 2210) and **E42** (document renewed → Dr 5050 for
   insurance, 5060 for registration/road tax/inspection, 5100 for a GPS subscription).
   `CompleteMaintenanceService` owns service completion end to end — the cost sum, the log,
   the car's odometer and release from `maintenance`, the schedule recalculation and the
   posting, in one transaction — and `RecordDocumentRenewalService` owns E42.
   `MaintenanceLogResource::complete_service` and the car page's own maintenance table both
   route through the service, so the posting happens from either entry point. Tests in
   `tests/Feature/MaintenancePostingTest.php` assert both legs, the sign, the
   `occurred_on` date, idempotency and rollback. No matrix update was needed: E41 and E42
   were already in [`../05-accounting-model.md`](../05-accounting-model.md); they were simply
   never built. Two related fixes fell out of it — `total_cost` is now derived rather than a
   free-text field that could read 100 against parts 5,000 + labour 3,000, and
   `MaintenanceSchedulerService::recomputeSchedule()` (built, tested, and with **no caller**
   anywhere in `app/`) finally has one.
6. **🟡 The three relation managers appear on the view page too, all writable.**
   **Fixed.** `ViewCar` and `EditCar` each override `getAllRelationManagers()`, and the
   read-only managers also return `isReadOnly()` true and refuse create/edit/delete, so the
   split does not rely on page routing alone. `AgreementsRelationManager`'s bulk delete is
   gone.
7. **🟡 `cars.is_active` is written and never read.**
   **Decided and fixed:** `is_active` **withdraws** the car. `availableCars()` now filters on
   it. Status says why a car is unavailable *today*; `is_active` says it is not part of the
   rentable fleet at all.
8. **🟡 Empty `->filters([])` and no default sort** on the busiest fleet table.
   **Fixed.** Six filters — status, category, ownership, active, document status, branch
   (gated) — plus `defaultSort('registration_number')`.
9. **🟡 No `canAccess()`.**
   **Fixed.** `RolePermissionSeeder` now seeds `fleet.view` (every staff role), `fleet.manage`
   (super_admin + manager) and `fleet.manage_maintenance` (super_admin, manager, maintenance
   officer) — which is the matrix's "full (maintenance), read (rest)" row expressed as a
   permission rather than a role check. `CarResource` gates `canAccess`/`canCreate`/`canEdit`/
   `canDelete` on them, and `getEloquentQuery()` pins a user without `branches.view_all` to
   `accessibleBranchIds()`.
10. **🟡 Deprecated `->actions([...])`** — README finding 3. **Fixed.** Migrated to
    `->recordActions([...])` / `->toolbarActions([...])` across all nine relation managers,
    and the deprecated `ListCars::getTableQuery()` override was replaced by
    `CarResource::getEloquentQuery()`.
11. **🔵 PHPStan: "Expression on left side of `??` is not nullable" at `CarResource.php:196`.**
    **Fixed.** Simplified to `$record->status->value`.
12. **🔵 `purchase_price` and `current_value` are on the create and edit forms with no
    gate.** They are not on the view page, so a receptionist cannot read them there, but
    can read them on `/cars/{id}/edit`. Now partly moot — `fleet.manage` restricts the edit
    form to a manager, who holds `reports.view_financials` anyway. **Still worth a decision**
    if the fleet write role is ever widened.
13. **🔵 GPS fields missing from the form.** `gps_device_id` / `gps_provider` are fillable
    and REQ-02 names GPS; the form never offered them. **Fixed.** Added to the Telematics section.
14. **🔵 The car pages were not covered by `ResourcePagesRenderTest`.** They are the only
    reason the missing `SpatieMediaLibraryFileUpload` import survived: a Filament schema is
    only resolved when the page opens, so an unimported component is invisible to every other
    kind of test. `tests/Feature/CarResourceTest.php` now renders index, create, view and edit.
15. **🔴 The Document Expiry traffic light never reached green.** Carbon 3's `diffInDays()` is
    **signed**, and the colour closure asked
    `parse($state)->diffInDays(today())` — operands reversed. A date 337 days out returned
    **−337**, so the `<= 30` amber test was true for *every* future date and the `success` arm
    was unreachable: a document valid for a year rendered amber while its own label correctly
    read "337 days left". **Fixed** — one `ViewCar::daysUntil()` helper now serves both the
    colour and the label, so the two can no longer disagree, and
    `tests/Feature/CarResourceTest.php` asserts all four bands including green.
16. **🔴 E42 posted to accounts the matrix did not describe.** A GPS subscription went to 5100
    with a `car_id`, contradicting E47's "branch only" dimension, and registration / vignette /
    inspection were credited to 2210 where E45 sanctions only 1010. **Fixed** by stating the
    postings rather than narrowing them: `docs/05-accounting-model.md` gained **E42b** (5060)
    and **E42c** (5100), each explaining why it does not conflict with the neighbouring row,
    and `MaintenancePoster` now maps the transaction type per case instead of "insurance or
    else Expense". E42 also refuses a document with no `issue_date` rather than dating a
    back-filled renewal today.
17. **🟡 Two N+1s, one of them introduced while fixing another.** The MTD profit column called
    `singleCarProfitability()` per row (measured: 40 queries for 10 rows) and the documents
    table asked "is this in the ledger?" twice per row. **Fixed:** `ListCars` resolves the
    whole fleet through `carProfitability()` once (10 rows → 4 queries), and `CarDocument`
    gained `HasLedgerPostings` plus a `withPostedToLedger()` scope so a page of documents costs
    one query — asserted in `MaintenancePostingTest`.
18. **🟡 `ViewCar`'s period properties were client-writable and fed `Carbon::parse()`.** A
    crafted Livewire payload was an unhandled 500. **Fixed** with `#[Locked]`, an `in()` rule on
    the select, and a `parseOr()` fallback; a test asserts the locked property throws.
19. **🟡 The `media` migration had no `down()`**, so `migrate:rollback` left the table and the
    next `migrate` would fail. **Fixed.**
20. **🟡 `fleet.view` had no deploy guarantee.** Until the seeder ran, `canAccess()` was false
    for everyone — Shield runs `define_via_gate => false` with no `Gate::before`, so an
    unseeded permission is denied to all, super_admin included, and the whole Fleet section
    would vanish. **Fixed** with `2026_07_30_120000_seed_fleet_permissions`, idempotent and
    mirroring the seeder.

## Checklist

- [x] Route `update_status_odometer` through `FleetStatusService::transition()`, and build its
      `Select` from `allowedTransitions()`; remove the dead `'cancelled'` target from the service
- [x] Move `block_car` into a service that validates the window against bookings and blocks
- [x] Remove `DeleteBulkAction`; restrict single delete and refuse it when bookings exist
- [x] Add `SpatieMediaLibraryFileUpload` for `gallery` and `damage`, and show them on the view page
- [x] Build `MaintenancePoster` (E41) and a document-renewal posting (E42) so the Profitability
      section's expense figure is complete — see also [`07-maintenance-log.md`](07-maintenance-log.md)
- [x] Section the 34-field form into Identity / Classification / Specification / Pricing /
      Acquisition / Telematics / Photos / Status; make `car_owner_id` `live()` on ownership type
- [x] Add GPS fields; limit `status` on create to available / out_of_service; remove `status`
      from the edit form
- [x] Freeze `chassis_number`, `registration_number` and `ownership_type` once in use
- [x] Add the status / category / ownership / active / expiring / branch filters and
      `defaultSort('registration_number')`
- [x] Badge the `status` column; add category and owner columns with eager loading
- [x] Add the six missing relation managers, grouped, and split read-only history onto the
      view page via `getAllRelationManagers()`
- [x] Make the three existing managers read-only on `ViewCar`
- [x] Extend Profitability to lifetime totals with a period picker (REQ-02)
- [x] `->actions(` → `->recordActions(`
- [x] Add `canAccess()` once a fleet permission exists
- [x] Decide whether `is_active` withdraws a car from availability — **it does**
- [x] Simplify `CarResource.php:196` to `$record->status->value`

Still open, and deliberately owned elsewhere:

- [ ] Serve private media back to the browser through a signed, authorised route —
      [`05-car-document.md`](05-car-document.md) gap 1. Uploads now land on the private disk;
      reading them still goes through Filament's own component only.
- [ ] E48 depreciation for company-owned cars, so a company car shows a cost of capital
      against a third-party car paying rent — [`../05-accounting-model.md`](../05-accounting-model.md).
- [ ] `purchase_price` / `current_value` visibility if the fleet write role is ever widened
      beyond manager (gap 12).
i18n is **not** on that list, and the reason is worth recording because it was got wrong once
already. A review of this work flagged "`CarResource` has zero `__()` calls" as untranslated
debt. That was a false finding: `AdminPanelProvider::boot()` calls `translateLabel()` on every
`Field`, `Column` and `Entry`, and translates every string `Section` heading, so a literal
`->label('Plate')` **is** routed through `__()` and resolved from the shared
`lang/{ar,fr}.json` dictionary. The provider's own comment states the strategy — one shared
dictionary "instead of 38 resources each carrying their own translation keys" — and
`LocaleTest` asserts it end to end on this very screen.

Acting on the false finding by introducing `lang/{en,fr,ar}/cars.php` broke that test, because
the label stopped resolving through the dictionary. The correct fix for the strings the
mechanism does *not* reach — action labels, notification titles and bodies, modal
descriptions, helper text, placeholders and filter *option* values — is `__('English text')`
plus a row in `lang/ar.json` and `lang/fr.json`, which is what this change does (+56 ar, +55
fr). **Do not add a per-resource PHP lang file for this panel.**

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CarResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/MaintenancePostingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase7Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CarResource.php app/Filament/Admin/Resources/CarResource
```

`FleetManagementTest` already asserts the illegal transitions and the document-expiry mirror;
both stay green, and the state-machine tests are now meaningful because the resource actually
calls `FleetStatusService::transition()`.

Measured after this work: **297 tests pass** (893 assertions), **Pint clean across 502 files**.
45 new tests across `CarResourceTest` and `MaintenancePostingTest`, up from a 252-test baseline.

**PHPStan is _not_ clean, and the earlier claim in this file that it was "clean at max level"
was wrong.** The repo carries **476** level-6 errors, down from 486 before this work; this
change introduced **zero** new ones. The bulk are `missingType.generics` on factories and
`missingType.iterableValue`, plus models without `@property` blocks — `Car`, `CarDocument`,
`MaintenanceLog` and `MaintenanceSchedule` gained theirs here because the E41/E42 code reads
their casts. Clearing the rest is its own task; do not describe the suite as clean until it is.

By hand: open a car as an accountant (holds `reports.view_financials`) and confirm the
Profitability section renders; open the same car as a receptionist and confirm the whole
section is absent, not merely blank. Then take a car to `sold` and try to move it back to
`available` from the row action — it must be refused with a notification, not a 500. Finally
complete a maintenance log for that car and re-read its Profitability expenses: the figure
must move by the invoice total.
