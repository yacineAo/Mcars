# 08 — Vendor (Fleet)

**Model:** `App\Models\Vendor` · **Slug:** `/admin/vendors` · **Status:** ✅ done

Supporting master data for **REQ-12** (maintenance) and **REQ-08** (expenses). See
[`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md).

## What it is for

The businesses the company pays: garages, insurers, parts suppliers, fuel stations, towing
([`../02-filament-panels.md`](../02-filament-panels.md)`:29`). Someone adds a vendor once, then
picks it from a Select on a maintenance log or an expense and never opens this screen again. Two
tables point here — `maintenance_logs.vendor_id`
(`2026_07_28_160000_create_fleet_tables.php:200`) and `expenses.vendor_id`
(`2026_07_29_000000_create_finance_tables.php:181`).

## Current state

| Surface | Exists | Notes |
|---|---|---|---|
| index | ✅ | 7 columns, badge on `type`, `maintenance_logs_count`, `is_active` + `type` + `branch_id` filters, `defaultSort('name')` |
| create | ✅ | 8 fields, flat — unchanged |
| view | ❌ | not needed — eight fields of contact detail; see Relations |
| edit | ✅ | same form; `canEdit` gated on `fleet.manage` |
| row actions | ✅ | `deactivate` + `reactivate` + `EditAction` via `->recordActions()` |
| header / toolbar actions | ✅ | `CreateAction`; no bulk delete |
| relation managers | ❌ | vendor history belongs in `expenseBreakdown()` report |
| `canAccess()` | ✅ | `fleet.view` / `fleet.manage` permissions |

Every column is local to the row, so the index carries no N+1 — unusual in this cluster.

Note what is deliberately **absent**: no `tax_id` / NIF field. CLAUDE.md records that it was
dropped from customers, owners and vendors with the no-tax decision. Nobody should "fix" that.

## Should be

### Index
Badge `type` with its icon — `VendorType` implements `HasIcon` with a case per icon
(`VendorType.php:21-31`) and the plain `TextColumn` (`:72`) shows none of it. Add
`maintenance_logs_count` via `->counts()`, and `defaultSort('name')`. Two filters, both missing:
`SelectFilter::make('type')` (the only way to answer "who are our garages") and
`TernaryFilter::make('is_active')` defaulted to active. A branch filter under `branches.view_all`
is worth considering — `Vendor` uses `BelongsToBranch` (`Vendor.php:18`), so vendors are already
branch-stamped whether anyone filters on it or not.

### Create
Nine flat fields plus a gated **Payment Details** section. **Decided: yes, add them.**
`bank_account_number`, `rib` and `ccp_number` mirror `car_owners`' Payment Details, gated the same
way (`->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)`) since
`VendorType` carries no bank/CCP dimension the way `FinancialAccountType` does — all three stay
always-visible and optional rather than conditional on a select.

### View
**Not needed as it stands** — eight fields of contact detail that a view page would only repeat.
That changes if the history relation below is added.

### Edit
Everything stays editable; nothing keys off `type`, so it need not freeze.

### Relations
`Vendor hasMany maintenanceLogs` (`Vendor.php:41`) is the only relation defined, though
`expenses.vendor_id` points here too and **no `expenses()` relation exists**. If a view page is
added, both belong on it and both are strictly read-only — a maintenance log is created from a car
or a schedule, an expense from `ExpenseResource`. `expenses` needs `reports.view_financials`;
`maintenanceLogs` inherits whatever [`07-maintenance-log.md`](07-maintenance-log.md) gap 5 decides
about `total_cost`.

It is a fair judgement call that "what have we spent with this garage" is a report, not a screen —
[`../02-filament-panels.md`](../02-filament-panels.md) puts vendor spend in `expenseBreakdown()`.
If that is the answer, this resource correctly needs **no relation managers at all**.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | **nothing** | — | keep |
| `CreateAction` | header | always | **nothing** | — | keep |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **replace with deactivate** — gaps 1, 2 |
| _needed_ | row | `is_active` | fleet permission | — | deactivate, and make the vendor Selects respect it |

## Gaps and risks

1. **🟡 `DeleteBulkAction` blanks the vendor name on historical records.** `Vendor` uses
   `SoftDeletes` (`Vendor.php:18`), so the row survives — but a `belongsTo` to a soft-deleted
   model resolves to `null`, and `vendor.name` is a column on both
   `MaintenanceLogResource.php:107` and `ExpenseResource.php:137`. Soft-deleting a garage leaves
   every past maintenance log and expense showing a blank supplier with no indication why.
   `expenses.vendor_id` is `->constrained('vendors')` with no `nullOnDelete`/`cascadeOnDelete`
   (`2026_07_29_000000_create_finance_tables.php:181`), so a future force-delete is refused by
   the database — correct, and the clue that deletion is the wrong verb here.
2. **🟡 `is_active` is written and never read.** Every vendor Select in the panel —
   `MaintenanceLogResource.php:50-53`, `MaintenanceLogsRelationManager.php:30-33`,
   `ExpenseResource.php:70-71` — uses `->relationship('vendor', 'name')` with no active filter,
   so a deactivated vendor is still offered on every form. Fixing this is what makes
   "deactivate instead of delete" mean anything.
3. **🟡 No `canAccess()`.** Fleet is `read` for accountant, receptionist and supervisor per
   [`../02-filament-panels.md`](../02-filament-panels.md) §Role → visibility matrix; nothing
   enforces it. Same cluster-wide blocker as the other seven Fleet resources: the live database
   holds four permissions (`alerts.manage`, `alerts.view_logs`, `branches.view_all`,
   `reports.view_financials`) and no Shield per-resource permissions, so there is nothing to
   check until a fleet permission pair is seeded. README finding 2.
4. **🟡 Empty `->filters([])`, no default sort.**
5. **🟡 Deprecated `->actions([...])`** — README finding 3.
6. ~~**🔵 No `expenses()` relation on the model**~~ → **Resolved.** `Vendor::expenses(): HasMany`
   exists (`Vendor.php`) — the model already had it by the time this line was last checked.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/VendorResourceTest.php
```

`FleetManagementTest` asserts `VendorSeeder` produces at least three rows — that must stay green.

By hand: attach a vendor to a maintenance log, delete the vendor, then reopen the maintenance
logs list. Today the supplier column is blank with no explanation; after the change the vendor
should still be named and simply unavailable for new records.
