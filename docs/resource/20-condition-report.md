# 20 — ConditionReport (Bookings)

**Model:** `App\Models\ConditionReport` · **Slug:** `/admin/condition-reports` · **Status:** ✅ audited — fine

Closes **REQ-05** (check-out / check-in inspection). See
[`../tasks/phase-05-bookings-contracts.md`](../tasks/phase-05-bookings-contracts.md).

## What it is for

The state of the car at handover and at return: odometer, fuel, cleanliness, damage, photos. It
is the evidence behind every closeout charge — excess kilometres, fuel shortfall, damage
deduction from the deposit — and the only defence when a customer disputes one. `checkInWithCharges`
posts the closeout extras **when a check-in report exists**, so this record directly determines
what the customer is billed.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | car + booking + type + readings columns; `type` / booking / car / damages filters; `defaultSort('performed_at','desc')` |
| create | ✅ | via `ConditionReportService::create()` — refuses a second report of the same type per booking, stamps `performed_by_id` |
| view | ✅ | readings with the paired report side by side, damage list, photo gallery (private disk, temporary URLs) |
| edit | ✅ | readings freeze once the booking is completed; notes and photos stay open |
| row actions | ✅ | Edit only — **no delete exists** |
| header / toolbar actions | 🟡 | `CreateAction`; **no bulk actions** |
| relation managers | ❌ | none — the pair is rendered inline on the view page |
| `canAccess()` | ✅ | `bookings.view`; write is `bookings.operate` |

## What was fixed

1. **🔴 → ✅ View page with the photos.** `ViewConditionReport` shows the report identity, the
   readings, the damage points, and the photo gallery. Photos come from the private disk
   (ADR-009) through `SpatieMediaLibraryImageEntry` — Filament serves them via temporary URLs,
   never a public path.
2. **🔴 → ✅ Delete removed.** `DeleteAction`, `DeleteBulkAction` and `canDelete()` are all
   absent/false. A report that justified a deposit deduction or an excess-km charge must outlive
   the dispute — the charge row is append-only in the ledger, and so is its justification. Not
   hidden, not permission-gated: *absent*, like the ledger's own write path.
3. **🔴 → ✅ Readings freeze once the booking is closed.** The edit form disables
   `booking_id`, `type`, `performed_at`, `odometer`, `fuel_level` and `is_clean` once the
   booking is `Completed` — the moment closeout charges are posted. Amending a reading after
   that would silently rewrite the justification of a ledger row. `notes` and `photos` stay
   editable: attaching more evidence never rewrites a reading. The predicate is
   `ConditionReport::isFrozen()`, on the model, not in the resource. `booking_id` and `type`
   lock even earlier — once the booking holds a second report (`hasOtherReport()`), because
   retyping the direction or re-pointing the evidence would create two reports of the same
   direction on a booking and the closeout takes "the latest check-in". A sole report stays
   correctable until then.
4. **🔴 → ✅ `canAccess()`.** The whole resource is behind `bookings.view`; create and edit
   behind `bookings.operate`. An accountant reads the evidence but never touches it.
5. **🟡 → ✅ One report per type per booking.** `ConditionReportService::create()` throws when
   the booking already holds a report of that type — two check-in reports would make the
   closeout charge basis ambiguous. The create form catches the `RuntimeException` and shows it
   on the booking field. The guard is double-backed: the `(booking_id, type)` unique index
   (`2026_08_10_000000_add_condition_reports_guard_constraints.php`) catches the concurrent
   double-submit that slips past the `exists()` check, and the same migration adds the missing
   `type` / `fuel_level` CHECK constraints (the enum convention). Edits go through the service
   too — `ConditionReportService::update()` refuses any change that would leave a booking with
   two reports of the same type, including re-pointing the evidence at a booking that already
   holds one.
6. **🟡 → ✅ Filters and the car.** The table shows the car alongside the booking reference and
   filters by `type`, booking, car (`relationship('booking.car', …)`) and damages
   (not-clean or non-empty `damage_points`, or clean).
7. **🟡 → ✅ `->actions([...])`.** Migrated to `->recordActions([...])`.
8. **Branch pinning.** `getEloquentQuery()` joins through the booking: a user without
   `branches.view_all` only sees reports of their own branch's bookings, server-side.

## Decisions taken while fixing

- **The paired out/in comparison is rendered inline, not as a relation manager.** Two inner
  columns on the readings section: this report's readings next to the paired report's (the
  opposite type for the same booking, via `ConditionReport::pairedReport()`). The pair is the
  point of the screen — a closeout charge is argued from the *difference* — so the paired
  column is hidden entirely until the booking has both reports.
- **Section headings must be static strings in `make()`.** The panel's
  `Section::configureUsing()` evaluates `getHeading()` at configure time, before a container
  exists — a closure passed to `Section::make()` crashes with `Component::$container must not
  be accessed before initialization`. Dynamic headings use `Section::make('static')
  ->heading(closure)`, which evaluates lazily at render.
- **`damage_points` is a flexible jsonb array** (plain strings or part/severity/note objects —
  nothing writes it yet; the checkout flow is the phase-05 gap). The view renders whatever is
  there defensively; the count is what the table shows.
- **The create form is not the checkout flow.** Reports are *ideally* born in the booking
  check-out/check-in actions; that flow still only writes `odometer_out`/`odometer_in` on the
  booking. Until it does, the resource create page is the writer — through the service, so the
  same-type guard holds for whatever writes later.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ConditionReportResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/CloseoutPricingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
```

`CloseoutPricingTest` covers charges derived from these readings — it must stay green.

By hand: check a car out and back in with a higher odometer and lower fuel, confirm the closeout
charges post, then confirm the report that justified them cannot be edited or deleted. Confirm a
damage photo is viewable only through a signed URL and 404s without one. Confirm a second
check-in report for the same booking is refused. Confirm an accountant sees the list but no
create/edit buttons.
