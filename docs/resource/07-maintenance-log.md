# 07 — MaintenanceLog (Fleet)

**Model:** `App\Models\MaintenanceLog` · **Slug:** `/admin/maintenance-logs` ·
**Status:** 🟡 mostly done — service start/complete/cancel, E41 posting, schedule recompute, car transitions all built; view page deferred until a ledger link is meaningful

Closes **REQ-12** (last oil change, last tyre change, last service) with
[`06-maintenance-schedule.md`](06-maintenance-schedule.md). This screen touches money, so read
[`../05-accounting-model.md`](../05-accounting-model.md) — posting **E41** — before changing it,
and [`../06-design-decisions.md`](../06-design-decisions.md) ADR-013.

## What it is for

The workshop record: what was done to which car, by which garage, when, at what odometer
reading, for how much. A maintenance officer works the screen — schedules the job, starts it,
completes it with the invoice figures. It is the only Fleet screen that produces a cost the
business pays, which is why the two actions on it matter more than the form.

## Current state

| Surface | Exists | Notes |
|---|---|---|---|
| index | ✅ | 8 columns, badges on type/status, eager-loaded, 6 filters including overdue, default sort |
| create | ✅ | 5 sections, `status`/`next_due_*` off the form, `total_cost` read-only, `performed_by_id` added |
| view | ❌ | absent; deferred until a ledger link is meaningful |
| edit | ✅ | sectioned; completed frozen (car/type/cost/odometer); cancelled read-only outright |
| row actions | ✅ | `start_service`, `complete_service`, `cancel_service`, Edit — via `->recordActions()` |
| header / toolbar actions | ✅ | `CreateAction`; no bulk delete |
| relation managers | ❌ | none needed here; see Relations |
| `canAccess()` | ✅ | `fleet.view` / `fleet.manage_maintenance` permissions |

## Money: what was asked, and what is true

**`decimal(18,2)`: yes.** `cost_parts`, `cost_labour` and `total_cost` are all
`decimal(18, 2)` (`2026_07_28_160000_create_fleet_tables.php:207-209`). No floats anywhere near
them.

**Via `MoneyCast`: no.** `MaintenanceLog::casts()` uses Laravel's `'decimal:2'` for all three
(`MaintenanceLog.php:48-50`). This is not a local oversight — grepped the whole of `app/`:
`MoneyCast` appears in exactly three places, its own class file and two docblocks
(`app/Support/Casts/MoneyCast.php`, `app/Support/Money.php:20`). **No model in the codebase uses
it**, and eighteen models use `decimal:2`. So this is a codebase-wide decision that was taken by
default rather than on purpose, and it should be settled once for the whole system rather than
argued here. Worth raising as its own task: CLAUDE.md names `MoneyCast` as a Phase 0 foundation
primitive and nothing consumes it.

**No total summed in the resource: no — this one is a real violation.**
`MaintenanceLogResource.php:142-144`:

```php
$parts = Money::of($data['cost_parts'] ?? '0');
$labour = Money::of($data['cost_labour'] ?? '0');
$total = $parts->plus($labour);
```

It uses the right primitive — `Money`, integer minor units, no float — and it is still a Filament
action deciding a money figure, which CLAUDE.md's layering table forbids in as many words ("A
Filament page never calls `Transaction::create()`, **never sums amounts**"). The sum belongs in
the service that owns service completion, next to the ledger posting it should trigger.

The create and edit forms make it worse: `total_cost` is an ordinary `TextInput` beside
`cost_parts` and `cost_labour` (`:65-73`) with no relationship between the three, so a log can be
saved with parts 5,000, labour 3,000 and total 100. `total_cost` is not a banned stored balance
in CLAUDE.md's sense — it is a two-column row-local sum, not an aggregate over the ledger — but
it is redundant state that can and will disagree with its parts. Make it read-only and derived,
or drop the column and sum the two in `ReportService`.

## Should be

### Index

Columns: `car.registration_number` as **Car**, `type` as a **badge**, `status` as a **badge**
(`MaintenanceStatus` implements `HasColor` and the column does not use it), `scheduled_for`,
`completed_at`, `vendor.name`, `odometer_at_service`, `total_cost` (gated — see gap 5).
Eager-load `car` and `vendor`.

Filters:

- `SelectFilter::make('status')` from `MaintenanceStatus` — **defaulting to open work**
  (`scheduled` + `in_progress`). That is the maintenance officer's worklist and today it is
  buried under every completed job the fleet has ever had.
- **Overdue** — `scheduled_for` in the past and status still `scheduled`.
- `SelectFilter::make('type')` from `MaintenanceType`.
- Date range on `completed_at`, for the accountant reconciling a month of workshop invoices.
- `SelectFilter::make('vendor_id')` and `car_id`, searchable.
- Branch filter, visible only with `branches.view_all`.

`defaultSort('scheduled_for', 'desc')`.

### Create

Section the 17 fields:

1. **Job** — car, type, `scheduled_for`, description.
2. **Assignment** — vendor, `performed_by_id`. The latter is fillable (`MaintenanceLog.php:35`)
   and absent from the form; "who did the work" is the first question asked when a repair fails.
3. **Completion** — `started_at`, `completed_at`, `odometer_at_service`, invoice number. These
   should be filled by the two actions, not typed here.
4. **Cost** — `cost_parts`, `cost_labour`, and `total_cost` **read-only, derived**.
5. **Notes**.

`status` should not be on the create form. A new log is `scheduled`; `start_service` and
`complete_service` move it. Offering the full enum lets a user jump straight to `completed`
without ever passing through the action that computes the total, sets the odometer, and (once
built) posts to the ledger.

`next_due_date` and `next_due_odometer` should come **off the form**. Both are fillable and cast
(`MaintenanceLog.php:34, 36` and `:53-54`), both are on the form (`:76-78`), and grepping the
repository finds **no reader for either** — three hits total, the form field, the `$fillable`
entry and the cast. The next-due numbers that are actually read live on `maintenance_schedules`
(`next_due_at` / `next_due_odometer`, consumed by `MaintenanceDueDetector`). Two places to
record the same fact, one of which nothing reads, is how they end up disagreeing. Either delete
the columns or make the completion service write the schedule's copy from them — see
[`06-maintenance-schedule.md`](06-maintenance-schedule.md) gap 1.

### View

**Add one, modestly**, once the ledger posting exists. A completed log with a cost becomes the
source document behind a `transactions` row, and the pattern already established for money
records ([`13-transaction.md`](13-transaction.md), [`15-expense.md`](15-expense.md)) is that
the source and its posting are reachable from each other. Sections: job and assignment, the
odometer and dates, the cost breakdown gated on `reports.view_financials`, and the resulting
ledger postings read-only. Until E41 is built there is nothing to link to, so this is second in
line behind gap 1.

### Edit

A **completed** log must be near-frozen. `car_id`, `type`, `completed_at`,
`odometer_at_service`, `cost_parts`, `cost_labour` and `total_cost` all become the basis of a
ledger posting and of the schedule recompute; changing them after the fact rewrites an expense
that has already been reported. Freeze them on completion and require a correction to be a
reversal, consistent with how the rest of the money path works. `notes` and `invoice_number`
stay editable.

A `cancelled` log should be read-only outright.

### Relations

**None on this screen.** A log points at one car, one vendor and one user; nothing points back
at it except `car_blocks.maintenance_log_id` (`2026_07_30_000000_create_booking_tables.php:42`)
and, once E41 exists, its `transactions` rows.

The reverse direction is already right: logs appear under their car through `CarResource`'s
`MaintenanceLogsRelationManager`, editable in place, which is where the office works. And they
should appear read-only under a vendor — see [`08-vendor.md`](08-vendor.md) §Relations.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `start_service` | row | `status === Scheduled` | maintenance write | **a maintenance service** | should also block the car; gap 4 |
| `complete_service` | row | status ∉ {Completed, Cancelled} | maintenance write | **a maintenance service** | gaps 1–4 |
| **Cancel** | row | status ∈ {Scheduled, InProgress} | maintenance write | same service | missing — `MaintenanceStatus::Cancelled` exists and no action reaches it |
| View / Edit | row | always / not completed | maintenance read / write | — | View page to be added |
| Create | header | always | maintenance write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 8 |

## Gaps and risks

1. **🔴 A completed service never reaches the ledger, and the phase doc says it does.**
   [`../05-accounting-model.md`](../05-accounting-model.md) posting **E41** specifies
   *maintenance completed → `Dr 5040 Maintenance & Repairs / Cr 1010 or 2210`*, dimensions
   `car`, `vendor`, `maintenance_log`. `docs/tasks/phase-04-ledger-cash-register.md:55` lists
   `MaintenancePoster` as built and `:74` states *"Completed maintenance logs and renewed car
   documents now post expenses stamped with `car_id`"*. Verified against the code:
   `app/Services/Accounting/` contains `AccountingService.php`, `CashSessionPoster.php`,
   `ExpensePoster.php`, `InterBranchTransferService.php`, `TransactionDraft.php` — **there is no
   `MaintenancePoster`**, and `complete_service` posts nothing
   (`MaintenanceLogResource.php:141-168`). Verified against the live database: **4 completed
   maintenance logs totalling 81,424 DZD, and 0 transactions whose `source_type` references a
   maintenance log**; all 14 postings to account 5040 came from manually created `Expense` rows.

   **Consequences, in order of seriousness:** the business's maintenance spend is missing from
   `ReportService::profitAndLoss()` and `expenseBreakdown()` unless someone re-enters it as an
   Expense; per-car expenses and net profit on [`02-car.md`](02-car.md)'s Profitability section
   are understated by whatever the workshop cost, which is exactly the comparison REQ-11 exists
   to make; and the same figure can be entered twice, once here and once as an Expense, with
   nothing detecting the double count. Build the poster, add its row to the posting matrix, and
   correct the phase doc.

   **Fixed** (with [`02-car.md`](02-car.md) gap 5). `app/Services/Accounting/MaintenancePoster.php`
   builds E41 — Dr 5040, Cr the chosen financial account's ledger account or 2210 AP–Suppliers
   when the bill is left on credit, stamped `car_id` with vendor, invoice number and odometer in
   `meta`, and `occurred_on` set to the completion date rather than today.
   `App\Services\Fleet\CompleteMaintenanceService` is the single owner of "a service was
   completed" and posts it. No posting-matrix row was needed: E41 was already in the matrix and
   simply unimplemented. A zero-cost service (warranty, in-house) completes without a posting,
   since a zero-amount row would fail `validateDraft()` and say nothing. Completing an
   already-completed log is refused, because the ledger is append-only and a double post could
   only be undone by a reversal. Both call sites — this resource and the car page's own
   maintenance table — go through the service, so neither can record work without the money.
   Covered by `tests/Feature/MaintenancePostingTest.php`.
2. **🔴 The resource computes the total.** `MaintenanceLogResource.php:142-144`. See §Money.
   **Fixed.** The sum moved into `CompleteMaintenanceService`, which derives `total_cost` from
   `Money::of(parts)->plus(labour)`. `total_cost` on the form is now `disabled()` and
   `dehydrated(false)`, so it can no longer read 100 against parts 5,000 and labour 3,000 — which
   mattered more once E41 started posting that figure.
3. **🔴 `complete_service` forces the car to `Available`, bypassing the state machine.**
   `MaintenanceLogResource.php:157-161` calls `$record->car->update([... 'status' => CarStatus::Available])`
   unconditionally. `FleetStatusService::transition()` exists to judge exactly this
   (`FleetStatusService.php:24-48`) and is called from nowhere in `app/` — see
   [`02-car.md`](02-car.md) gap 1. Two concrete failures: a car serviced mid-rental (`rented`)
   is set to `Available` while its booking is still active, which is a car the availability
   search will now offer twice; and a car that was `sold` or `returned_to_owner` is dragged back
   out of a terminal state, which the transition table forbids. Route it through the service and
   only move the car when it was actually in `maintenance`.

   **Fixed.** `CompleteMaintenanceService::releaseCar()` calls
   `FleetStatusService::transition()`, and only when the car's status is actually
   `maintenance` — so a car serviced mid-rental stays `rented` and a terminal car is never
   dragged back. The odometer is advanced with `max()`, so a completion can never lower a
   recorded reading.
4. **🔴 `complete_service` does not recompute the schedule.** The whole point of recording
   `completed_at` and `odometer_at_service` is to advance the next service due, and
   `MaintenanceSchedulerService::recomputeSchedule()` is never called from here or anywhere else.
   Full evidence in [`06-maintenance-schedule.md`](06-maintenance-schedule.md) gap 1. Practical
   effect: the service-due alert keeps firing for a car that was serviced this morning.

   **Fixed.** `CompleteMaintenanceService` calls `recomputeSchedule()` inside the same
   transaction as the log write and the posting. The scheduler itself was left in place rather
   than reimplemented — it already had the calculation, it only lacked a caller — but its date
   assignments were changed from `toDateString()` strings to Carbon instances so they match the
   `date` casts on `MaintenanceSchedule`.
5. **🟡 `total_cost` is shown to every staff role.** `TextColumn::make('total_cost')->money('DZD')`
   (`:104-106`) with no gate and no `canAccess()` on the resource. Per CLAUDE.md,
   `reports.view_financials` gates "revenue, profit, cash flow and receivables" — a workshop
   invoice is none of those, so this is a decision to take rather than an obvious hole. But it is
   a decision: the role matrix
   ([`../02-filament-panels.md`](../02-filament-panels.md)`:97`) gives the maintenance officer
   full access to maintenance and the accountant read access to Fleet, and someone should say out
   loud whether a receptionist sees what the garage charged.
6. **🟡 `status` is settable on create and on edit**, so a user can reach `completed` without
   passing through `complete_service` — skipping the total, the odometer update and (once built)
   the ledger posting. See Create.
7. **🟡 `next_due_date` / `next_due_odometer` are collected and read by nothing.** See Create.
8. **🟡 `DeleteBulkAction` on maintenance logs.** Soft deletes, so the data survives, but a log is
   a car's service history — the thing a buyer or an insurer asks for — and once E41 exists it is
   the source document behind a posted expense. Deleting the source of a ledger row while the row
   remains is the shape of problem ADR-003 exists to avoid. Remove the bulk action; refuse single
   delete once a posting exists.
9. **🟡 N+1 on the index.** `car.registration_number` (`:90`) and `vendor.name` (`:107`) are two
   relation lookups per row; `ListMaintenanceLogs` is a bare stub with no eager loading.
10. **🟡 Empty `->filters([])`, no default sort**, on the maintenance officer's worklist.
11. **🟡 No `canAccess()`.** Same cluster-wide blocker: the live database holds four permissions
    (`alerts.manage`, `alerts.view_logs`, `branches.view_all`, `reports.view_financials`) and no
    Shield per-resource permissions, so a `maintenance.manage` permission has to be seeded before
    the role matrix can be enforced. README finding 2.
12. **🟡 Deprecated `->actions([...])`** — README finding 3.
13. **🔵 PHPStan false positive, not a runtime bug — do not "fix" the comparisons.** PHPStan
    reports *"Strict comparison using === between string and MaintenanceStatus::Scheduled will
    always evaluate to false"* at `MaintenanceLogResource.php:128` and two `!==` variants at
    `:169`. Investigated: `MaintenanceLog::casts()` returns
    `'status' => MaintenanceStatus::class` (`MaintenanceLog.php:44`), and verified at runtime
    against the seeded database that `get_debug_type($log->status)` is
    `App\Enums\MaintenanceStatus`. Both actions therefore appear correctly; larastan is reading
    the `varchar` column type instead of `casts()`, and PHPStan says so itself ("Because the type
    is coming from a PHPDoc…"). Same species as CashSession — see
    [`14-cash-session.md`](14-cash-session.md) gap 9 — and the same fix: the model has **no class
    docblock at all**, so add `@property MaintenanceStatus $status` and
    `@property MaintenanceType $type`. **Note for the index in
    [`README.md`](README.md):** finding 6 names four sites; this file is a fifth, and it was found
    by running PHPStan over the Fleet cluster rather than by inspection.
14. **🔵 `started_at` and `completed_at` are `timestamp` columns edited with a `DatePicker`.**
    `:61-62` and `:135` use `DatePicker` for columns declared `timestamp`
    (`2026_07_28_160000_create_fleet_tables.php:204-205`) and cast `datetime`
    (`MaintenanceLog.php:46-47`), so the time is truncated to midnight while `scheduled_for` —
    genuinely a `date` — is treated identically. Harmless for the schedule recompute, which uses
    `toDateString()`, but it makes "when did the car go in" unanswerable. Use
    `DateTimePicker` for the two timestamps.
15. **🔵 `MaintenanceStatus::Cancelled` is unreachable from the UI.** Both actions guard against
    it and nothing sets it. Add a cancel action or explain why a scheduled job can only be
    deleted.
16. **🔵 Action labels do not translate.** "Start Service" and "Complete Service" are set with
    `->label()` and never `->translateLabel()`, while `lang/fr.json` already carries both. See
    [`03-car-owner.md`](03-car-owner.md) gap 10 — one panel-level fix covers the cluster.

## Checklist

- [x] Build `MaintenancePoster` and post E41 from the completion service; add its row to
      [`../05-accounting-model.md`](../05-accounting-model.md) and correct
      `docs/tasks/phase-04-ledger-cash-register.md:55,74`
- [x] Move service start and completion into a service: the total, the car status via
      `FleetStatusService`, the schedule recompute, and the ledger posting, in one transaction
- [x] Make `total_cost` read-only and derived on the forms, or drop the column
- [x] Route the car status change through `FleetStatusService::transition()`, and only when the
      car was in `maintenance`
- [x] Call `MaintenanceSchedulerService::recomputeSchedule()` on completion
- [x] Remove `status` from the create and edit forms; add a cancel action
- [x] Remove `next_due_date` / `next_due_odometer` from the form, and decide whether the columns
       survive
- [x] Add the status (default open), overdue, type, completion-date, vendor, car and branch
       filters; `defaultSort('scheduled_for', 'desc')`
- [x] Badge `type` and `status`; eager-load `car` and `vendor`
- [x] Decide whether `total_cost` is gated, and record the decision
- [x] Section the form; add `performed_by_id`; use `DateTimePicker` for the two timestamps
- [x] Freeze a completed log; make a cancelled one read-only
- [x] Remove `DeleteBulkAction`
- [x] `->actions(` → `->recordActions(`
- [x] Add `@property MaintenanceStatus $status` / `@property MaintenanceType $type` to the model
       docblock; leave the comparisons alone
- [x] Add `canAccess()` once a maintenance permission exists
- [ ] Separate task, whole codebase: decide whether `MoneyCast` is adopted or removed

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/MaintenanceLogResource.php
```

`LedgerWiringTest` is where the E41 posting-matrix test belongs — it must assert the debit lands
on 5040, the credit on the paying account, and that `car_id` and the source document are both
stamped. After adding the model docblock, PHPStan must report **zero** errors for this file with
the three comparisons untouched.

By hand: complete a service for a car with parts 5,000 and labour 3,000 and confirm three things
that are all false today — the total reads 8,000 and is not editable, a transaction appears in
the ledger against account 5040 stamped with the car, and the car's Profitability expenses on
`ViewCar` move by 8,000. Then complete a service on a car that is currently `rented` and confirm
it does **not** become `Available`.
