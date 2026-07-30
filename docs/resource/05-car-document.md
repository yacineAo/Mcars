# 05 — CarDocument (Fleet)

**Model:** `App\Models\CarDocument` · **Slug:** `/admin/car-documents` · **Status:** ✅ complete

Closes **REQ-13** (expiry notifications) and half of **ADV-02** (document archive). Read
[`../06-design-decisions.md`](../06-design-decisions.md) **ADR-009** — this is the resource it
governs.

## What it is for

Every piece of paper a car needs to be on the road: insurance, technical inspection,
registration card, road-tax vignette. [`../02-filament-panels.md`](../02-filament-panels.md)`:26`
defines the screen in one line — *"Global list filterable by expiry — the renewals worklist"*.
Someone in the office opens it on Monday morning, sees what lapses this month, and renews it.

The list exists. The expiry filter does not, which is the whole feature.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 9 columns, 5 filters (expiry window defaulted, current-only, type, car, branch), `defaultSort('expiry_date')`, eager-load + `withPostedToLedger` |
| create | ✅ | 10 fields including `SpatieMediaLibraryFileUpload` for the scan |
| view | ❌ | absent by design — modal preview action on row is enough |
| edit | ✅ | `car_id`/`type` frozen after creation; superseded docs gated by `canEdit()` returning false |
| row actions | ✅ | `renew`, `preview_scan`, Edit, Delete — via `->recordActions([...])` |
| header / toolbar actions | 🟡 | `CreateAction` only; no `DeleteBulkAction` |
| relation managers | ❌ | none needed |
| `canAccess()` | ✅ | gates on `fleet.view` |

**What is right, verified.** ADR-009's schema half holds: `car_documents` has **no `*_path`
column** — the table is `car_id`, `type`, `number`, `issuer`, `issue_date`, `expiry_date`,
`cost`, `reminder_days_before`, `replaced_by_id`, `notes`, audit columns, timestamps,
`softDeletes` (`2026_07_28_160000_create_fleet_tables.php:159-175`) — and `CarDocument`
implements `HasMedia` with a single `document` collection pinned to the **private** disk
(`CarDocument.php:50-54`).

The resource also does no date arithmetic of its own, which is correct. Two things read these
rows and both live outside it: `CarDocumentObserver` mirrors the latest expiry per type onto
`cars.*_expiry_date` (`app/Observers/CarDocumentObserver.php:29-58`, registered at
`AppServiceProvider.php:34`), and `CarDocumentExpiringDetector` raises the Phase 8 alert. The
detector reads two fields set on this form and nothing else: it skips rows with a
`replaced_by_id` and takes the lead time as
`GREATEST(rule.days_before, reminder_days_before)` (`CarDocumentExpiringDetector.php:36-42`).
So `reminder_days_before` genuinely matters here, and the `renew` action's setting of
`replaced_by_id` is what stops a superseded document alerting forever.

## Should be

### Index

Columns: `car.registration_number` as **Car**, `type` as a **badge**, `number`, `expiry_date`,
**days remaining** (a formatted state on `expiry_date`, coloured — red past, amber inside the
reminder window, plain otherwise), `issuer`, `issue_date` (toggleable), and a **superseded**
icon from `replaced_by_id`.

Filters — this is the work:

- **Expiry window.** The filter the resource was specified for: expired / expiring in 30 days /
  expiring in 90 days / all. Default it to "expiring or expired", so opening the screen *is*
  the worklist.
- `TernaryFilter` on `replaced_by_id` — **current only, defaulted to current**. Without it a
  renewed document sits in the list beside its replacement looking equally valid, which is how
  someone renews the same policy twice.
- `SelectFilter::make('type')` from `CarDocumentType`.
- `SelectFilter::make('car_id')`, searchable.
- Branch filter, visible only with `branches.view_all` — note `car_documents` has no
  `branch_id`, so this has to go through `car` the way the detector does
  (`CarDocumentExpiringDetector.php:45`).

`defaultSort('expiry_date')` ascending — the soonest lapse first. Eager-load `car`.

### Create

Order: car, type, number, issuer, issue date, expiry date, cost, `reminder_days_before`,
**the scan**, notes. `expiry_date` should be required — a document with no expiry is invisible
to the detector (`whereNotNull('expiry_date')`) and therefore silently outside the feature.

`reminder_days_before` should carry helper text saying what it does: it raises the floor under
the alert rule's lead time, it does not replace it.

The missing field is the file. See gap 1.

### View

**Not needed as a page**, with one caveat: once a scan is attached, the user needs somewhere to
see it. A modal preview action on the row is enough for a nine-field record; a full view page is
not warranted unless the document grows a history of its own. If the scan preview turns out to
need more room, add the page then.

### Edit

`car_id` and `type` should freeze after creation. Moving a document to a different car or
retyping an insurance policy as a registration card silently rewrites two of
`cars.*_expiry_date` through the observer, and the observer only recalculates the type it was
given — the old type's mirror is left stale. Everything else stays editable; documents are
transcribed from paper and typos are normal.

A document with `replaced_by_id` set should be read-only. It is history.

### Relations

**None.** A car document is a leaf: it points at one car and optionally at the document that
replaced it (`replaced_by_id`, a self-reference — `2026_07_28_160000_create_fleet_tables.php:169`).
The reverse direction is already wired the right way round: documents appear under their car via
`CarResource`'s `DocumentsRelationManager`, which is where the office maintains them in place.
This screen exists for the cross-fleet worklist view, not for per-car editing.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `renew` | row | `replaced_by_id === null` | fleet write | **a service** | gaps 2 and 3 |
| Edit | row | `replaced_by_id === null` | fleet write | — | freeze superseded rows |
| **Preview scan** | row | media exists | fleet read | a policy-checked controller | gap 1 |
| Create | header | always | fleet write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 6 |

## Gaps and risks

1. **🔴 There is no way to attach or read a document scan, so ADV-02 is unmet and ADR-009 is
   half-built.** The model registers the private-disk collection and nothing uses it: grepped
   all of `app/Filament/` — **zero** occurrences of `SpatieMediaLibraryFileUpload`. And ADR-009
   specifies files "served through a **policy-checked controller** issuing short-lived signed
   URLs"; `routes/web.php` contains exactly two application routes, `exports.download` and
   `locale.switch`. No media controller exists.

   **Investigated and downgraded: there is no open hole.** `php artisan route:list` shows
   `GET|HEAD storage/{path}` with **no middleware**, and both the `local` and `private` disks
   resolve to the same directory — verified, `Storage::disk('private')->path($p)` and
   `Storage::disk('local')->path($p)` both return
   `/var/www/html/storage/app/private/$p` (`config/filesystems.php:35-57`). That looks like a
   private file served publicly, and it is not: Laravel's `ServeFile::hasValidSignature()`
   requires `$request->hasValidRelativeSignature()` unless the disk declares
   `visibility: public`, and `local` declares none. Probed against the running stack with a real
   file under `storage/app/private` — an unsigned request returns **403**, not the bytes. So the
   route is not the defect.

   The residual gap is the one ADR-009 named: a signed URL is a *bearer token*, not a policy
   check. Whoever holds the link gets the file for its lifetime regardless of role, and nothing
   ties it to the record's permissions. Build the controller ADR-009 asked for, then add the
   upload field and a preview action.
2. **🔴 `renew` performs two writes with no transaction.**
   `CarDocumentResource.php:110-122` creates the replacement document, then separately updates
   the old row's `replaced_by_id`. If the second write fails, both documents are live and
   `replaced_by_id` is null on the old one, so `CarDocumentExpiringDetector` alerts on both
   forever and the mirror column takes whichever expiry is later. Wrap it, and move it out of
   the resource — this is a two-row state transition, which is a service's job under ADR-013.
3. **🔴 A renewal cost never reaches the ledger.** `renew` collects `cost`
   (`CarDocumentResource.php:106-108`) and writes it to the row.
   [`../05-accounting-model.md`](../05-accounting-model.md) posting **E42** says an insurance
   renewal is `Dr 5050 Insurance / Cr 1010 or 2210` with `car` and `car_document` dimensions,
   and `docs/tasks/phase-04-ledger-cash-register.md:74` records this as done — *"renewed car
   documents now post expenses stamped with `car_id`"*. It is not done. Verified against the
   live database: **24 car documents carrying 584,949 DZD of cost, and zero transactions with a
   `source_type` referencing `CarDocument`**. Every 5040/5050-class posting in the ledger came
   from a manually created `Expense`. Consequences: the business's insurance spend is invisible
   in `ReportService::profitAndLoss()`, and the per-car expense figure on
   [`02-car.md`](02-car.md)'s Profitability section is understated by whatever the paperwork
   costs. Same root cause as [`07-maintenance-log.md`](07-maintenance-log.md) gap 1.
4. **🔴 No expiry filter and no default sort on the renewals worklist.** The resource's stated
   purpose is a list filterable by expiry; it has `->filters([])` (`:88`) and no ordering, so
   the screen opens on an arbitrary slice of every document the fleet has ever had, current and
   superseded together. See Index.
5. **🟡 `expiry_date` is optional.** A document saved without one is excluded from the detector
   by `whereNotNull('expiry_date')` and never alerts. Require it, or make the omission visible
   on the index.
6. **🟡 `DeleteBulkAction` on documents.** These are the evidence a car was insured on the day
   of an accident. Soft deletes preserve them, but bulk-deleting them is not an office task, and
   deleting a *replacement* nulls the predecessor's `replaced_by_id`
   (`replaced_by_id` is `nullOnDelete`, `2026_07_28_160000_create_fleet_tables.php:169`) which
   quietly puts the old document back into the alert set. Remove the bulk action.
7. **🟡 N+1 on the index.** `car.registration_number` (`:71`) is a lookup per row;
   `ListCarDocuments` is a bare stub with no eager loading.
8. **🟡 `cost` is on an ungated form.** Minor next to gap 3, but a receptionist can read and
   change what the business paid to insure the fleet.
9. **🟡 No `canAccess()`.** Same cluster-wide position: Fleet is `read` for accountant,
   receptionist and supervisor per
   [`../02-filament-panels.md`](../02-filament-panels.md) §Role → visibility matrix, nothing
   enforces it, and the live database holds four permissions with no Shield per-resource
   permissions to gate on (README finding 2).
10. **🟡 Deprecated `->actions([...])`** — README finding 3.
11. **🔵 `car_id` and `type` editable after creation**, which can leave a stale mirror column.
    See Edit.
12. **🔵 Action label does not translate.** "Renew Document" is set with `->label()` and never
    `->translateLabel()`; `lang/fr.json` already carries "Renouveler le document". See
    [`03-car-owner.md`](03-car-owner.md) gap 10 — one panel-level fix.
13. **🔵 No `branch_id` on `car_documents`.** Not a defect today — the detector scopes through
    `car` (`CarDocumentExpiringDetector.php:45`) and ADR-004 is marked superseded for the
    as-built system — but Phase 10's branch scope has to reach this table through the car, so
    note it rather than discover it.

## Checklist

- [x] Add the expiry-window filter and default it to "expiring or expired";
      `defaultSort('expiry_date')`
- [x] Add a current-only `TernaryFilter` on `replaced_by_id`, defaulted to current
- [x] Add type, car and branch (via `car`) filters; eager-load `car` + `withPostedToLedger`
- [x] Add a **days remaining** column, coloured (`danger`/`warning`/`gray`), and a superseded icon
- [x] Build the policy-checked media controller ADR-009 specifies — `DocumentMediaController` at
      `GET /media/car-documents/{id}/download` with a `temporarySignedRoute` + `fleet.view` check;
      then add `SpatieMediaLibraryFileUpload` for the `document` collection and a preview action
- [x] Move `renew` into `RecordDocumentRenewalService::renew()`, inside one transaction
- [x] Post the renewal cost through `AccountingService` (E42 / E42b / E42c) inside the same
      transaction — `renew()` calls `MaintenancePoster::postDocumentRenewed()` when cost > 0
- [x] Require `expiry_date` in the form schema
- [x] Freeze `car_id` and `type` on edit via `->disabled(fn (... $operation === 'edit'))`;
      superseded docs gated by `canEdit()`/`canDelete()` returning false
- [x] Remove `DeleteBulkAction`; toolbar is empty
- [x] `->actions(` → `->recordActions(`
- [x] Add `canAccess()` gated on `fleet.view`; `cost` gated on `reports.view_financials`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`FleetManagementTest` covers the expiry-mirror observer on create, update and delete — all three
must stay green, and the frozen `type` field is what stops a fourth case from being needed.
`Phase8Test` covers `CarDocumentExpiringDetector`, whose behaviour depends on `replaced_by_id`
and `reminder_days_before` being set the way this screen sets them.

By hand: renew an insurance document and confirm the old row is marked superseded, drops out of
the default index view, and stops appearing in the next `alerts:evaluate` sweep. Then check the
car's `insurance_expiry_date` matches the new document. Once the upload field exists, attach a
scan and confirm the file lands under `storage/app/private` and that an unsigned request for it
still returns 403.
