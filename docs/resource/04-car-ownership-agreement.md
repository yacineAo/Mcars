# 04 — CarOwnershipAgreement (Fleet)

**Model:** `App\Models\CarOwnershipAgreement` · **Slug:** `/admin/car-ownership-agreements` ·
**Status:** 🟢 complete

Closes **REQ-03** with [`03-car-owner.md`](03-car-owner.md). Read
[`../06-design-decisions.md`](../06-design-decisions.md) **ADR-006** before changing anything
here — this table exists precisely so that rent terms are not columns on `cars`. Postings E32,
E33 and E36 in [`../05-accounting-model.md`](../05-accounting-model.md) are what an agreement
eventually produces.

## What it is for

The contract between the business and a car owner: which car, whose, on what model
(`fixed_monthly` / `revenue_share` / `hybrid`), from when to when, at what rate, payable on
which day of the month, over how many instalments. A manager opens it to agree terms; an
accountant opens it to see which agreements are live this month, because those are the ones
that accrue rent.

It is the only screen in the panel whose data is guarded by a database `EXCLUDE` constraint,
and that shapes the two actions on it.

## Current state

| Surface | Exists | Notes |
|---|---|---|---|
| index | ✅ | 8 columns (2 toggleable), badges on status/model, 5 filters including live-on-date; `defaultSort('start_date', 'desc')` |
| create | ✅ | 4 sections (Parties, Terms, Schedule, Notes); `model` is `live()`; conditional rent/share fields; `status` removed (defaults to draft) |
| view | ✅ | Parties, Terms, Schedule, Notes infolist + read-only `InstallmentsRelationManager` gated on `reports.view_financials` |
| edit | ✅ | Same sections; car/owner/model/start/rent/share frozen when active or when instalments exist |
| row actions | ✅ | `activate` (via service), `end` (via service with date picker), `generate_instalments` (gated), View, Edit, Delete (hidden when any instalment exists) — all via `->recordActions([...])` |
| header / toolbar actions | ✅ | only `CreateAction`; `DeleteBulkAction` removed |
| relation managers | ✅ | `InstallmentsRelationManager` read-only on view, gated on `reports.view_financials` |
| `canAccess()` | ✅ | `fleet.view` / `fleet.manage` |

The same form terms/schedule sections are extracted into
`CarOwnershipAgreementResource::getTermFields()` and shared with both `AgreementsRelationManager`
classes (CarResource and CarOwnerResource).

## Should be

### Index

Columns: `car.registration_number` as **Car**, owner name, `model` as a **badge**, `status` as
a **badge**, `start_date`, `end_date`, `monthly_rent_amount`, `share_percentage`. Both status
and model enums implement `HasColor` and neither column uses `->badge()`, so the colour is
defined and never shown.

Filters, none of which exist:

- `SelectFilter::make('status')` from `AgreementStatus` — **defaulting to active**. An
  accountant asking "what accrues this month" wants the active set, and today it is mixed in
  with drafts and ended agreements.
- `SelectFilter::make('model')`.
- **Live on a date** — a date filter matching `start_date <= d AND (end_date IS NULL OR
  end_date >= d)`. That predicate is exactly what
  `OwnerStatementService::generateMonthlyInstallments()` runs
  (`OwnerStatementService.php:28-33`), so the screen and the accrual job would agree on what
  "live" means.
- `SelectFilter::make('car_owner_id')` and `car_id`, both searchable.
- Branch filter, visible only with `branches.view_all`.

`defaultSort('start_date', 'desc')`.

`end_date` should render "—" for an open-ended agreement rather than blank; a null end date is
meaningful here, it means "until further notice".

### Create

Section the 13 fields and make the form follow the model:

1. **Parties** — car, owner. Both required.
2. **Terms** — `model` as `live()`; `monthly_rent_amount` required and visible only for
   `fixed_monthly` or `hybrid`; `share_percentage` required and visible only for
   `revenue_share` or `hybrid`. Today both are always shown and neither is ever required, so a
   `fixed_monthly` agreement saves happily with no rent.
3. **Schedule** — `payment_day_of_month`, `installments_count`, `first_due_date`, `grace_days`.
4. **Notes**.

`status` should not be on the create form at all. A new agreement is a draft; making it active
is the `activate` action, because that is the transition the `EXCLUDE` constraint judges.

`share_percentage` needs `->maxValue(100)`. The column is `decimal(5, 2)`
(`2026_07_28_160000_create_fleet_tables.php:144`), so 999.99% is storable and there is no
`CHECK` constraint and no form rule stopping it.

### View

**Add one**, modest. An agreement is a contract and its instalments are the record of whether
it has been honoured — that is the reason to have a page rather than a row. Sections: parties
(with links to the car and the owner), terms, schedule, status history, and the
`ownerInstallments` table below. Gate the money on `reports.view_financials`.

If the view page is judged not worth it, then the instalments belong on the owner's view page
instead (see [`03-car-owner.md`](03-car-owner.md)) — but they have to be somewhere reachable,
and today they are not.

### Edit

Once an agreement is **active**, almost nothing may change. `car_id`, `car_owner_id`,
`model`, `start_date`, `monthly_rent_amount` and `share_percentage` all determine instalments
that have already been accrued and posted to the ledger; editing them rewrites last year's
rent, which is the exact failure ADR-006 was written to prevent. Freeze all six once
`status === Active` or once any `owner_installment` exists for the agreement, and enforce it in
a service rather than with `->disabled()` alone.

What stays editable on an active agreement: `notes`, `grace_days`, and `payment_day_of_month`
going forward. A rate change is a **new agreement** with the old one ended — that is the whole
point of the table.

### Relations

`CarOwnershipAgreement` has only `belongsTo` relations of its own (`car`, `carOwner` —
`CarOwnershipAgreement.php:53-63`), but `owner_installments.car_ownership_agreement_id` points
back at it (`OwnerStatementService.php:39`).

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `ownerInstallments` (inverse) | **view** | yes | `reports.view_financials` | sequence, period, due date, amount due, amount paid *(derived)*, status |

Read-only: an instalment is accrued by `OwnerStatementService` and posted to the ledger, so it
is not a row to hand-edit under its parent. `amount_paid` must be derived — CLAUDE.md names
`owner_installments.amount_paid` as a banned stored column.

Nothing else. The car and the owner are `belongsTo` pointers and belong on the view page as
links.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `activate` | row | `status !== Active` | fleet write | **a service** | can 500 today; gap 1 |
| `end` | row | `status === Active` | fleet write | **a service** | gap 2 |
| View / Edit | row | always | fleet read / write | — | View page to be added |
| Create | header | always | fleet write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 5 |

## Gaps and risks

1. **🔴 `activate` can hand the user a 500 from a database constraint.**
   `CarOwnershipAgreementResource.php:114-121` calls
   `$record->update(['status' => AgreementStatus::Active])` behind a bare
   `requiresConfirmation()`. The `EXCLUDE` constraint `agreements_no_overlap` forbids two
   *active* agreements whose `daterange(start_date, end_date, '[)')` overlap for the same car
   (`2026_07_28_160000_create_fleet_tables.php:248-255`), so activating a draft that overlaps a
   live agreement raises an unhandled `QueryException`. This is the constraint doing its job
   and the UI failing to. `FleetManagementTest` asserts the database rejects the overlap;
   nothing asserts the screen survives it. Move the transition into a service that checks for
   an overlapping active agreement first and returns a validation message naming the
   conflicting agreement.
2. **🔴 `end` mutates a contract from the resource and leaves the money behind.**
   `CarOwnershipAgreementResource.php:129-133` sets `status = Ended` and `end_date = now()`
   inline. Two consequences it does not handle: instalments already accrued for periods after
   today stay `pending` and stay posted to account 2200, and `FleetStatusService::transition()`
   refuses a terminal car status while an active agreement exists
   (`FleetStatusService.php:41`) — so ending an agreement is the gate for selling the car, and
   it is a one-click action with no checks. It also allows `end_date < start_date` when a
   future-dated agreement is ended today; there is no `CHECK` constraint on the date order
   (the migration adds enum checks only), and the `EXCLUDE` predicate no longer applies once
   the status leaves `active`, so nothing catches it. Ending an agreement belongs in a service
   that validates the date, decides what happens to future instalments, and records why.
3. **🔴 Three scheduling fields are collected and read by nothing; the accrual job that would
   read them never runs.** `installments_count`, `first_due_date` and `grace_days` are on this
   form and on `AgreementsRelationManager`, and grepping `app/` finds **no reader** outside
   those two forms. `OwnerStatementService::generateMonthlyInstallments()` does read
   `payment_day_of_month` (`OwnerStatementService.php:50`) — but it hardcodes
   `'total_installments' => 999` (`:65`), ignoring `installments_count`, and it has **no
   artisan command and no `Schedule::` entry** (`routes/console.php` schedules only
   `alerts:evaluate`, `alerts:digest` and the report/backup jobs). Its only caller anywhere is
   `tests/Feature/Phase6Test.php:116`. So in the running system an agreement generates no
   instalments at all; they are hand-created on `OwnerInstallmentResource`, and REQ-03's
   "payment due date, payment status, number of instalments" is answered by manual data entry.
   Either schedule the generator and make it honour `installments_count` and `first_due_date`,
   or add a **Generate instalments** action here that calls it for a chosen month.
4. **🟡 N+1 on the index.** `car.registration_number` and `carOwner.first_name` (`:86`, `:89`)
   are two relation lookups per row, and `ListCarOwnershipAgreements` is a bare stub with no
   eager loading. At 25 rows that is 50 extra queries.
5. **🟡 `DeleteBulkAction` on signed agreements.** An agreement is the basis for accrued rent
   already in the append-only ledger. Soft deletes keep the row, but a bulk delete of the
   documents behind posted transactions is not an operation to leave in a dropdown. Remove it;
   keep single delete, refused when any instalment exists.
6. **🟡 The form lets model and amounts disagree**, and `share_percentage` is unbounded. See
   Create.
7. **🟡 `status` is settable on create**, which lets a user skip `activate` and drive straight
   into the `EXCLUDE` constraint from the create page instead — the same 500 by a different
   route.
8. **🟡 Empty `->filters([])`, no default sort**, on the table an accountant needs filtered by
   "active" more than any other in the cluster.
9. **🟡 No `canAccess()`.** Fleet is `read` for accountant, receptionist and supervisor per
   [`../02-filament-panels.md`](../02-filament-panels.md) §Role → visibility matrix; nothing
   enforces it, and a receptionist can activate and end rent contracts. Same cluster-wide
   blocker: four permissions exist in the live database and no Shield per-resource permissions
   (README finding 2).
10. **🟡 Deprecated `->actions([...])`** — README finding 3.
11. **🔵 PHPStan false positive, not a runtime bug — do not "fix" the comparison.** PHPStan
    reports *"Strict comparison using !== between string and AgreementStatus::Active will
    always evaluate to true"* at `CarOwnershipAgreementResource.php:122` and *"… using ===
    … will always evaluate to false"* at `:140`. Investigated: `CarOwnershipAgreement::casts()`
    returns `'status' => AgreementStatus::class` (`CarOwnershipAgreement.php:41`), and verified
    at runtime against the seeded database that `get_debug_type($agreement->status)` is
    `App\Enums\AgreementStatus` and `$agreement->status === AgreementStatus::Active` returns
    **true**. Both actions therefore appear correctly. The cause is larastan taking the type
    from the `varchar` column rather than from `casts()`, and PHPStan says so itself
    ("Because the type is coming from a PHPDoc…"). This is the same false positive as
    CashSession — see [`14-cash-session.md`](14-cash-session.md) gap 9 — and the same fix: the
    model has **no class docblock at all**, so add `@property AgreementStatus $status` and
    `@property AgreementModel $model` to it. Changing the comparisons would break working
    actions. README finding 6.
12. **🔵 The create form is duplicated in `AgreementsRelationManager`.** Twelve of thirteen
    fields, restated (`AgreementsRelationManager.php:26-62`). Any rule added in one place —
    the `live()` model, the 100% cap, the frozen fields — has to be added twice or it is not
    enforced. Extract one shared schema.
13. **🔵 Action labels do not translate.** "Activate" and "End Agreement" are set with
    `->label()` and never `->translateLabel()`; `AdminPanelProvider` configures `Field`,
    `Column`, `Entry`, `Section`, `Table` and `Schema` but not `Action`
    (`AdminPanelProvider.php:65-83`), while `lang/fr.json` already carries both strings. See
    [`03-car-owner.md`](03-car-owner.md) gap 10 — one panel-level fix covers the cluster.

## Checklist

- [x] Move `activate` into a service (`OwnerAgreementService::activate()`) that checks for an
      overlapping active agreement and returns a validation message instead of a `QueryException`
- [x] Move `end` into a service (`OwnerAgreementService::end()`) that validates
      `end_date >= start_date` and waives future-period pending instalments
- [x] Add a Generate-instalments row action that calls
      `OwnerStatementService::generateForAgreement()` honouring `installments_count` and
      `first_due_date` (gated on `reports.view_financials`)
- [x] Remove `status` from the create form; new agreements default to `draft` via model
      `$attributes`
- [x] Make `model` `live()`; require the matching amount; cap `share_percentage` at 100
- [x] Freeze car, owner, model, start date and amounts once active or once instalments exist
      (via `->disabled()` closures in the resource form)
- [x] Add status (default active), model, live-on-date (date-picker filter matching start/end),
      owner and car filters; `defaultSort('start_date', 'desc')`
- [x] Eager-load `car` and `carOwner` via `getEloquentQuery()`
- [x] Badge `status` and `model` columns; render null `end_date` as "—"
- [x] Add view page (`ViewCarOwnershipAgreement`) with parties, terms, schedule, notes and a
      read-only `InstallmentsRelationManager` gated on `reports.view_financials`
- [x] Add `@property AgreementStatus $status` / `@property AgreementModel $model` to
      `CarOwnershipAgreement` docblock; leave the comparisons alone
- [x] Extract shared form schema (`CarOwnershipAgreementResource::getTermFields()`) consumed by
      both the resource and the two `AgreementsRelationManager` classes (CarResource and
      CarOwnerResource)
- [x] Remove `DeleteBulkAction`; keep single `DeleteAction` hidden when instalments exist
- [x] `->actions(` → `->recordActions(`
- [x] Add `canAccess()` with `fleet.view` / `fleet.manage`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CarOwnershipAgreementResource.php
```

`FleetManagementTest` asserts the `EXCLUDE` constraint rejects overlapping active agreements —
that must stay green, and a new test should assert the `activate` action reports the conflict
instead of throwing. `Phase6Test` covers `OwnerStatementService`, which is where the accrual
half belongs.

After adding the model docblock, PHPStan must report **zero** errors for this file, with the
two comparisons untouched.

By hand: create two draft agreements for the same car with overlapping dates, activate the
first, then activate the second — today that is a 500. Then end an agreement whose
`start_date` is in the future and inspect the stored `end_date`.
