# 02 — Car (Fleet)

**Model:** `App\Models\Car` · **Slug:** `/admin/cars` · **Status:** 🔴 needs work

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
| index | ✅ | 7 columns, `->filters([])` **empty**, no default sort |
| create | ✅ | **34 fields, flat, zero sections** (`CarResource.php:56-139`) |
| view | ✅ | `ViewCar` — 5 infolist sections, Profitability correctly gated and sourced |
| edit | ✅ | same flat form as create; nothing frozen |
| row actions | ✅ | `book_now`, `update_status_odometer`, `block_car`, View, Edit — via deprecated `->actions([...])` (`:168`) |
| header / toolbar actions | 🟡 | `CreateAction` (`ListCars.php:17`); `DeleteBulkAction` in a group (`:246`) |
| relation managers | 🟡 | 3 of 9 relations: `agreements`, `documents`, `maintenanceLogs` |
| `canAccess()` | ❌ | absent |

`CreateCar` and `EditCar` are bare stubs (13 lines each). `ListCars` adds only a
`CreateAction`.

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

### Index

Columns, in order: `registration_number` as **Plate** (sortable, searchable — it is what
staff say out loud), brand + model as one searchable column, `category.name`,
`status` as a **badge**, `daily_rate`, `odometer`, `owner.first_name` (toggleable, hidden by
default — most cars are company-owned), `is_active` icon. Eager-load `category` and `owner`
in `ListCars` before adding those two.

`status` is rendered as a plain `TextColumn` (`:156`). `CarStatus` implements `HasColor` and
`HasIcon` with a colour per case (`CarStatus.php:24-48`) and none of it reaches the screen —
add `->badge()`. Do **not** add `->color(fn (CarStatus $s) => ...)`: that closure signature
is the bug `tests/Feature/ResourcePagesRenderTest.php` exists to catch.

Filters, none of which exist:

- `SelectFilter::make('status')` from `CarStatus` — the filter a receptionist needs first.
- `SelectFilter::make('car_category_id')` relationship filter.
- `SelectFilter::make('ownership_type')` — company versus third-party is the split every
  fleet question divides on.
- `TernaryFilter::make('is_active')`, defaulting to active.
- **Documents expiring** — a filter over the `*_expiry_date` mirror columns. REQ-13's
  renewals worklist lives on `CarDocumentResource`, but "which cars are not road-legal
  today" is a fleet question and belongs here too.
- Branch filter, visible only with `branches.view_all`.

`defaultSort('registration_number')`.

A receptionist needs plate, category, status, daily rate. A manager additionally wants
ownership type and, gated on `reports.view_financials`, a month-to-date profit column
sourced from `ReportService::carProfitability()` — never a stored column, and only as a
toggleable one, since it is a per-row report query.

### Create

Thirty-four fields in one flat column is the reason this screen feels unusable. Section it:

1. **Identity** — brand, model, trim, year, colour, chassis number (VIN), engine number,
   registration number (plate), registration date.
2. **Classification** — category, ownership type, `car_owner_id` (shown only when
   `ownership_type` is third-party — make it `live()`).
3. **Specification** — body type, transmission, fuel type, seats, doors.
4. **Pricing** — daily / weekly / monthly rate, security deposit, mileage limit per day,
   extra-km price, late-hour fee.
5. **Acquisition** — purchase date, purchase price, current value.
6. **Telematics** — `gps_device_id`, `gps_provider`. Both are in `$fillable`
   (`Car.php:80-81`) and neither is on the form; REQ-02 names GPS explicitly.
7. **Photos** — `gallery` and `damage` (see gap 4).
8. **Status** — status, odometer, `is_active`, notes.

What must **not** be settable here: `odometer_updated_at` (derived from an odometer change,
and currently absent from the form, which is correct), and the four `*_expiry_date` mirror
columns — they are a rebuildable cache of `car_documents` maintained by
`CarDocumentObserver` (`app/Observers/CarDocumentObserver.php`), and they are correctly
absent from the form. Keep them out.

`status` on create should be limited to `available` / `out_of_service`. A car cannot be
born `rented`.

### View

Keep, and rebuild it as the tabbed page [`../02-filament-panels.md`](../02-filament-panels.md)
§Car page specifies: **Overview** (identity, status, photos, odometer) · **Documents** ·
**Maintenance** (history + next due) · **Bookings** (full contract history) ·
**Profitability** · **Owner** (third-party cars only) · **Activity**. The five flat sections
that exist today map onto Overview and Profitability; the other five tabs are the missing
relation tables below.

Two additions to Profitability itself. It reports **this month only** (`ViewCar.php:119`,
`startOfMonth()`/`endOfMonth()`), but REQ-02 asks for *total* profit, expenses and rental
days — lifetime, and ideally a period picker. And `road_tax_expiry_date` in the Document
Expiry section should show *how many days* remain, coloured, not just a date; that section
is the reason a maintenance officer opens the page.

### Edit

`chassis_number` and `registration_number` must freeze once the car has a booking or a
contract. Both are unique (`2026_07_28_160000_create_fleet_tables.php:98,100`) and both are
printed onto signed contracts — changing a plate after a contract exists makes the archived
PDF disagree with the database. `ownership_type` must freeze while an active
`car_ownership_agreement` exists, for the same reason ADR-006 keeps rent terms off `cars`.

`status` must come out of the edit form entirely: it is a state machine (see gap 1).

Everything else — rates, specification, notes, telematics — stays editable.

### Relations

`Car hasMany`: `agreements`, `documents`, `maintenanceLogs`, `maintenanceSchedules`,
`bookings`, `contracts`, `blocks`, `fines`, `ownerInstallments` (`Car.php:130-182`). Three
are wired; six are not.

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `documents` | **edit** | no — the office maintains these | — | built (`DocumentsRelationManager`); keep |
| `maintenanceLogs` | **edit** | no — maintained in place | — | built (`MaintenanceLogsRelationManager`); keep |
| `agreements` | **edit** | no | — | built (`AgreementsRelationManager`); keep, see gap 6 |
| `maintenanceSchedules` | **edit** | no — intervals are maintained here | — | task type, interval km/days, next due at, next due odometer |
| `bookings` | **view** | **yes** — history | — | reference, customer, pickup/return, status, total |
| `contracts` | **view** | **yes** — history | — | number, status, signed at |
| `fines` | **view** | **yes** — history | — | notice number, violation date, amount, liability, status |
| `blocks` | **view** | yes — written by the `block_car` action | — | reason, starts/ends at, notes |
| `ownerInstallments` | **view** | **yes** | `reports.view_financials` | due date, amount, status |

Filament renders `getRelations()` on **both** the view and the edit page —
`ViewRecord.php:29` and `EditRecord.php:46` both use `Concerns\HasRelationManagers` — so the
three writable managers currently appear on `ViewCar` as well. Splitting read-only history
onto view and editable tables onto edit therefore needs an explicit
`getAllRelationManagers()` override on each page, not just an entry in `getRelations()`.

Nine tabs is too many to scan. Group them the way the panel doc's tab list already does:
**Documents**, **Maintenance** (logs + schedules), **Bookings** (bookings + contracts +
fines + blocks), **Owner** (agreements + instalments).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `book_now` | row | status ∈ {available, reserved} | fleet read | — (URL to `BookingResource`) | correct as-is; drop `openUrlInNewTab()` — a booking is the next step, not a side trip |
| `update_status_odometer` | row | status not terminal | fleet write | **`FleetStatusService::transition()`** | today it does not; gap 1 |
| `block_car` | row | status ∈ {available, reserved} | bookings write | **a service owning `CarBlock` creation** | today it writes the row inline; gap 2 |
| View / Edit | row | always | fleet read / write | — | keep |
| Create | header | always | fleet write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 3 |

## Gaps and risks

1. **🔴 `update_status_odometer` bypasses the fleet state machine.**
   `CarResource.php:200-212` calls `$record->update(['status' => $data['status'], ...])`
   directly, and its `Select` offers **every** `CarStatus` (`:183-185`).
   `FleetStatusService::transition()` exists, enforces a transition table and refuses a
   terminal status while an active ownership agreement exists
   (`FleetStatusService.php:24-48`) — and it is called from **nowhere in `app/`**. Grepped:
   the only reference outside its own file is `tests/Feature/FleetManagementTest.php:14`.
   So the service is tested and unused, while the UI lets a receptionist move a car from
   `sold` back to `available`, or straight from `available` to `rented` without a booking.
   The action must call the service, and the `Select` must be populated from
   `FleetStatusService::allowedTransitions($record)`, so the illegal targets are not offered
   in the first place. While doing that, note the service's own defect: its `reserved` row
   allows `'cancelled'` (`FleetStatusService.php:18`) and `CarStatus` has no `Cancelled`
   case (`CarStatus.php:14-20`) — the DB `CHECK` constraint would reject it. Dead entry;
   remove it.
2. **🔴 `block_car` writes a `CarBlock` from the resource.** `CarResource.php:227-241` calls
   `$record->blocks()->create([...])`, sets `created_by_id` by hand, and checks nothing:
   not that `ends_at > starts_at`, not that the window overlaps a confirmed booking, not
   that another block already covers it. Blocking a car is exactly the kind of availability
   decision ADR-013 says a resource must not make — `CarBlock` conflicts are read by
   `BookingAvailabilityService::conflictsFor()`, so a bad block silently changes what the
   booking path believes. Move it to a service; `app/Actions/` does not exist, so
   `app/Services/Booking/`.
3. **🔴 `DeleteBulkAction` on cars.** A car is referenced by bookings, contracts, ledger
   rows, fines and instalments. Soft deletes protect the data, but nothing about this
   business wants multi-select car deletion one click away. `car_ownership_agreements` and
   `car_documents` are `cascadeOnDelete` on `car_id`
   (`2026_07_28_160000_create_fleet_tables.php:139,161`), so a future force-delete takes the
   paperwork with it. Remove the bulk action; keep single delete, restricted, refused when
   the car has any booking.
4. **🔴 REQ-02 asks for photos and there is no way to attach one.** `Car` registers
   `gallery` and `damage` collections (`Car.php:184-188`) and `CarDocument` registers
   `document` on the **private** disk (`CarDocument.php:50-54`). Grepped the whole panel:
   **no Filament resource, page or relation manager uses
   `SpatieMediaLibraryFileUpload`** — zero hits under `app/Filament/`. The collections exist
   and nothing writes to or reads from them, so ADV-02 ("digital archive for all contracts
   and documents") is unmet for cars. See [`05-car-document.md`](05-car-document.md) gap 1
   for the serving half of the same problem.
5. **🔴 The Profitability section's expense figure is structurally understated.** It is
   correctly sourced from `ReportService`, but the ledger it reads has no maintenance or
   document-renewal rows in it. `docs/tasks/phase-04-ledger-cash-register.md:55` records
   `MaintenancePoster` as built and `:74` claims completed maintenance logs and renewed
   documents post expenses stamped with `car_id`. Verified against the code:
   `app/Services/Accounting/` contains `AccountingService`, `CashSessionPoster`,
   `ExpensePoster`, `InterBranchTransferService`, `TransactionDraft` — **there is no
   `MaintenancePoster`**, and postings E41 (maintenance completed) and E42 (insurance
   renewed) from [`../05-accounting-model.md`](../05-accounting-model.md) are unimplemented.
   Verified against the live database: 4 completed maintenance logs totalling 81,424 DZD and
   24 car documents totalling 584,949 DZD in costs, against **zero** transactions with a
   `source_type` referencing either. Every 5040 posting in the ledger came from a manually
   created `Expense`. So a car's "Expenses" and "Net Profit" on this page are wrong by
   whatever the workshop cost. See [`07-maintenance-log.md`](07-maintenance-log.md) gap 1.
6. **🟡 The three relation managers appear on the view page too, all writable.** See
   Relations. A receptionist reading a car's history can create and bulk-delete its
   ownership agreements from `ViewCar`, because `AgreementsRelationManager` carries
   `CreateAction`, `EditAction` and `DeleteBulkAction`
   (`AgreementsRelationManager.php:87-97`).
7. **🟡 `cars.is_active` is written and never read.** `BookingAvailabilityService::availableCars()`
   filters on `status = 'available'` and on category and branch — not on `is_active`
   (`BookingAvailabilityService.php:32-41`). A car deactivated on this screen is still
   offered by availability search. Either wire it in or relabel the toggle so it stops
   implying it withdraws the car.
8. **🟡 Empty `->filters([])` and no default sort** on the busiest fleet table.
9. **🟡 No `canAccess()`.** Per [`../02-filament-panels.md`](../02-filament-panels.md)
   §Role → visibility matrix, Fleet is `full` for manager, `read` for accountant,
   receptionist and supervisor, and `full (maintenance), read (rest)` for the maintenance
   officer. Nothing enforces any of it. As with every fleet resource, the honest blocker is
   that the live database holds exactly four permissions and no Shield per-resource
   permissions — a fleet read/write pair must be seeded before `canAccess()` has anything to
   check. README finding 2.
10. **🟡 Deprecated `->actions([...])`** — README finding 3.
11. **🔵 PHPStan: "Expression on left side of `??` is not nullable" at `CarResource.php:196`.**
    Verified and it is neither a false positive nor a bug — it is dead defensive code.
    The line is `'status' => $record->status?->value ?? $record->status` inside
    `fillForm()`. `Car` casts `status` to `CarStatus` (`Car.php:90`) *and* declares
    `@property CarStatus $status` (`Car.php:27`), so `$record->status` is never null, the
    `?->` never short-circuits, and the `?? $record->status` fallback is unreachable.
    Unlike the enum-comparison warnings elsewhere in the panel (README finding 6), the fix
    here **is** to change the code: `'status' => $record->status->value`. Nothing breaks
    either way.
12. **🔵 `purchase_price` and `current_value` are on the create and edit forms with no
    gate.** They are not on the view page, so a receptionist cannot read them there, but
    can read them on `/cars/{id}/edit`. What the business paid for a vehicle is closer to
    `reports.view_financials` territory than a daily rate is; worth a decision rather than
    an accident.
13. **🔵 GPS fields missing from the form.** `gps_device_id` / `gps_provider` are fillable
    and REQ-02 names GPS; the form never offers them.

## Checklist

- [ ] Route `update_status_odometer` through `FleetStatusService::transition()`, and build its
      `Select` from `allowedTransitions()`; remove the dead `'cancelled'` target from the service
- [ ] Move `block_car` into a service that validates the window against bookings and blocks
- [ ] Remove `DeleteBulkAction`; restrict single delete and refuse it when bookings exist
- [ ] Add `SpatieMediaLibraryFileUpload` for `gallery` and `damage`, and show them on the view page
- [ ] Build `MaintenancePoster` (E41) and a document-renewal posting (E42) so the Profitability
      section's expense figure is complete — tracked in [`07-maintenance-log.md`](07-maintenance-log.md)
- [ ] Section the 34-field form into Identity / Classification / Specification / Pricing /
      Acquisition / Telematics / Photos / Status; make `car_owner_id` `live()` on ownership type
- [ ] Add GPS fields; limit `status` on create to available / out_of_service; remove `status`
      from the edit form
- [ ] Freeze `chassis_number`, `registration_number` and `ownership_type` once in use
- [ ] Add the status / category / ownership / active / expiring / branch filters and
      `defaultSort('registration_number')`
- [ ] Badge the `status` column; add category and owner columns with eager loading
- [ ] Add the six missing relation managers, grouped, and split read-only history onto the
      view page via `getAllRelationManagers()`
- [ ] Make the three existing managers read-only on `ViewCar`
- [ ] Extend Profitability to lifetime totals with a period picker (REQ-02)
- [ ] `->actions(` → `->recordActions(`
- [ ] Add `canAccess()` once a fleet permission exists
- [ ] Decide whether `is_active` withdraws a car from availability
- [ ] Simplify `CarResource.php:196` to `$record->status->value`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase7Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CarResource.php app/Filament/Admin/Resources/CarResource
```

`FleetManagementTest` already asserts the illegal transitions and the document-expiry mirror;
both must stay green, and the state-machine tests become meaningful for the first time once the
resource actually calls the service.

By hand: open a car as an accountant (holds `reports.view_financials`) and confirm the
Profitability section renders; open the same car as a receptionist and confirm the whole
section is absent, not merely blank. Then take a car to `sold` and try to move it back to
`available` from the row action — today it succeeds, and it must stop. Finally complete a
maintenance log for that car and re-read its Profitability expenses: the figure must move.
