# 32 — ReportDefinition (Reports)

**Model:** `App\Models\ReportDefinition` · **Slug:** `/admin/report-definitions` · **Status:** 🟡 partial

Closes part of **REQ-16** (saved schedules). See
[`../tasks/phase-09-reports.md`](../tasks/phase-09-reports.md) §Phase 9b.

## What it is for

A saved report that runs on a cron and emails itself. "Last month's P&L to the accountant, first
Monday of every month." `RunScheduledReports` sweeps every minute, matches cron expressions, and
dispatches an `ExportJob` carrying `report_definition_id` so the finished file is emailed to
`schedule_email`.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | name, type, format, cron, email, enabled, last sent; filters on type/format/enabled |
| create | ✅ | sectioned: name/type/format, Scope, Schedule |
| view | ❌ | see Relations — this is the main gap |
| edit | ✅ | maps `parameters` JSON to `param_*` fields and back |
| row actions | ✅ | `EditAction` — via `recordActions()` |
| header / toolbar actions | 🟡 | `CreateAction`; `DeleteBulkAction` |
| relation managers | ❌ | none, though `ReportDefinition hasMany pendingExports` |
| `canAccess()` | ✅ | `reports.view_financials` |

Correctly built: gated; labels translated through `lang/*/reports.php`; nested under Reports in the
navigation via `getNavigationParentItem()` resolved from `ReportResource::getNavigationLabel()` —
which matters because Filament matches the parent by *rendered label* and the app runs in French,
so a hardcoded `'Reports'` would have silently orphaned the item.

## Should be

### Index
Add what a scheduling screen needs and lacks: the **next run time** derived from the cron
expression, and a clear indicator when a schedule is enabled but has never sent
(`last_sent_at IS NULL` with `schedule_enabled = true` is the signal that a cron expression is
wrong). Filter for enabled-but-never-sent.

### Create
The cron field is the weak point: a free-text `TextInput` with a helper string
(`'0 8 * * 1'`). An operator has no feedback until a run silently fails to happen. It should
validate the expression and show the next few run times in plain language — "next: Monday 4 Aug,
08:00". `Cron\CronExpression` is already a dependency (`RunScheduledReports` uses it), so this is
presentation over an existing capability.

`schedule_email` is a single address. Confirm whether one recipient is intended; a monthly P&L
usually goes to more than one person.

### View
**Add one.** It is the missing surface: a saved report's value is its run history — did it fire,
did it succeed, was it emailed — and that is currently invisible. Sections: the definition and its
scope, the schedule with next run time, `last_sent_at`, then the runs table.

### Edit
`report_type` should freeze once runs exist, or the definition's history becomes a mix of two
different reports under one name. Scope, format, cron and email stay editable.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `pendingExports` | **view** | yes | `reports.view_financials` | requested at, status, format, size, download |

Read-only: runs are created by the scheduler or by `ReportResource`, never here. Each row should
link to its report view page in [`31-report.md`](31-report.md), so a failed scheduled run can be
diagnosed and retried without hunting for it in the main archive.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `EditAction` | row | always | `reports.view_financials` | — | freeze `report_type` once runs exist |
| `DeleteBulkAction` | toolbar | always | `reports.view_financials` | — | cascades to run history — gap 6 |
| _needed_ | row | always | `reports.view_financials` | `RunScheduledReports` logic | **Run now** — currently you must wait for the cron |

## Gaps and risks

1. **🟡 No view page, so run history is invisible.** A schedule that has been failing silently for
   three months looks identical to one that works. The only clue is `last_sent_at` on the index.
2. **🟡 A failed scheduled run notifies nobody.** `ExportJob` logs a warning and marks the export
   failed; the email simply does not arrive. For a report someone relies on monthly, silence is
   the worst failure mode. Worth an alert rule on repeated failure — the Phase 8 machinery exists
   for exactly this.
3. **🟡 Cron is free text with no validation or preview** — see Create. The most likely
   misconfiguration on the screen, with no feedback loop.
4. **🟡 `report_type` editable after runs exist** — see Edit.
5. **🟡 Timezone of the cron is unstated.** The app runs `Africa/Algiers` and
   `RunScheduledReports` evaluates against `CarbonImmutable::now()`, so `0 8 * * 1` means 08:00
   Algiers. That is correct but invisible to the operator — the helper text should say so.
6. **🔵 `DeleteBulkAction`.** Lower risk than elsewhere: deleting a definition cascades to its
   `pendingExports` (`report_definition_id` is `cascadeOnDelete`), which removes run history rather
   than ledger data. Still worth confirming that cascade is intended, because it silently discards
   the audit of what was sent to whom.
7. **🔵 Single email recipient** — see Create.

## Checklist

- [ ] Add a view page with the read-only, gated runs table linking to each report
- [ ] Validate the cron expression and preview the next run times
- [ ] State the schedule timezone (`Africa/Algiers`) in the helper text
- [ ] Add a next-run column and an enabled-but-never-sent filter
- [ ] Alert on repeated scheduled-run failure via the Phase 8 alert rules
- [ ] Freeze `report_type` once runs exist
- [ ] Confirm the cascade delete of run history is intended
- [ ] Decide whether multiple email recipients are needed

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ReportsSectionTest.php
docker compose exec app php artisan reports:run-scheduled --now="2026-09-07 08:00"
```

The `--now` option exists specifically to test cron matching without waiting — use it to prove a
definition fires when expected.

By hand: create a schedule due in the next minute, let the scheduler fire it, and confirm the file
arrives in Mailpit (http://localhost:8025) and that the run appears in the definition's history.
