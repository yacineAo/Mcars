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
| view | ✅ | added — mirrors the fields, days-remaining, the scan preview action and a link to the replacement document once superseded |
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

**Added**, once view pages became standard across every resource. The nine fields, the
days-remaining state, the cost (gated on `reports.view_financials`) and the **Preview scan**
action all live there now; a superseded document links to whatever replaced it via
`replaced_by_id`. No relation manager — a car document is still a leaf.

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

This section audited the resource as it stood before this round's fixes; **12 of the 13 findings
below are now resolved**, verified against the running code rather than assumed from the
checklist. Only gap 7 survived the check — the eager-load fix it describes was real and has been
applied (see `withLedgerFlag()`).

1. ~~**🔴 There is no way to attach or read a document scan...**~~ → **Resolved.**
   `DocumentMediaController` exists (`app/Http/Controllers/DocumentMediaController.php`), wired to
   `GET /media/car-documents/{carDocument}/download` behind `temporarySignedRoute` in
   `routes/web.php`. `SpatieMediaLibraryFileUpload` is on the create/edit form and a
   **Preview scan** row action opens the signed URL.
2. ~~**🔴 `renew` performs two writes with no transaction.**~~ → **Resolved.**
   `App\Services\Fleet\RecordDocumentRenewalService::renew()` owns both writes inside one
   transaction; the resource only calls it.
3. ~~**🔴 A renewal cost never reaches the ledger.**~~ → **Resolved.**
   `RecordDocumentRenewalService::renew()` posts through `MaintenancePoster::postDocumentRenewed()`
   (E42 / E42b / E42c) in the same transaction as the two document writes.
4. ~~**🔴 No expiry filter and no default sort...**~~ → **Resolved.** The expiry-window filter
   (defaulted to "expired or expiring"), the current-only `TernaryFilter`, type/car/branch
   filters and `defaultSort('expiry_date', 'asc')` are all in place.
5. ~~**🟡 `expiry_date` is optional.**~~ → **Resolved.** `->required()` on the form.
6. ~~**🟡 `DeleteBulkAction` on documents.**~~ → **Resolved.** No bulk action is registered.
7. **🟡 N+1 on the index.** Still real on inspection — `withLedgerFlag()` eager-loaded `media`
   but not `car`, so `car.registration_number` was a lookup per row. **Fixed in this round**:
   `withLedgerFlag()` now does `->with(['car', 'media'])`; regression-tested in
   `CarDocumentResourceTest.php` (constant query count at 3 and 8 rows, not scaling with N).
8. ~~**🟡 `cost` is on an ungated form.**~~ → **Resolved.** `->visible(fn (): bool =>
   Auth::user()?->can('reports.view_financials') ?? false)`.
9. ~~**🟡 No `canAccess()`.**~~ → **Resolved.** Gates on `fleet.view`.
10. ~~**🟡 Deprecated `->actions([...])`.**~~ → **Resolved.** Uses `->recordActions([...])`.
11. ~~**🔵 `car_id` and `type` editable after creation.**~~ → **Resolved.** Both `->disabled()`
    once `$operation === 'edit'`.
12. ~~**🔵 Action label does not translate.**~~ → **Resolved.** `->label(__('Renew Document'))`,
    with matching `fr.json`/`ar.json` entries.
13. **🔵 No `branch_id` on `car_documents`.** Still accurate — not a defect today, but Phase 10's
    branch scope has to reach this table through `car`, so note it rather than discover it.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/CarDocumentResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/MaintenancePostingTest.php
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
