# 32 — ReportDefinition (Reports)

**Model:** `App\Models\ReportDefinition` · **Slug:** `/admin/report-definitions` · **Status:** ✅ done

Closes **REQ-16** (saved schedules). See
[`../tasks/phase-09-reports.md`](../tasks/phase-09-reports.md) §Phase 9b.

## What it is for

A saved report that runs on a cron and emails itself. "Last month's P&L to the accountant, first
Monday of every month." `RunScheduledReports` sweeps every minute, matches cron expressions, and
dispatches an `ExportJob` carrying `report_definition_id` so the finished file is emailed to
`schedule_email`.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | name, type, format, cron, email, enabled, next run, last sent; filters on type/format/enabled/`enabled_never_sent` |
| create | ✅ | sectioned: name/type/format, Scope, Schedule; cron validated + next-run preview; comma-separated recipients |
| view | ✅ | sections for definition/scope/schedule, next run, last sent, and the runs history table linking to each report |
| edit | ✅ | maps `parameters` JSON to `param_*` fields and back; `report_type` frozen once runs exist |
| row actions | ✅ | `ViewAction`, `EditAction`, **Run now** |
| header / toolbar actions | ✅ | `CreateAction`; `DeleteBulkAction` |
| relation managers | ❌ | none — run history is an embedded table on the view page instead |
| `canAccess()` | ✅ | `reports.view_financials` |

Correctly built: gated; labels translated through `lang/*/reports.php`; nested under Reports in the
navigation via `getNavigationParentItem()` resolved from `ReportResource::getNavigationLabel()` —
which matters because Filament matches the parent by *rendered label* and the app runs in French,
so a hardcoded `'Reports'` would have silently orphaned the item.

## Decisions taken (Round 32)

- **View page** (`Pages/ViewReportDefinition`): read-only infolist + runs table (requested at,
  status badge, format, size, error, per-row "open" link to the report's view page in `31-report.md`,
  10 s poll, newest first). A failed scheduled run can be diagnosed and retried from here.
- **Cron field**: validated by `App\Rules\ValidCronExpression` (blank allowed; `Cron\CronExpression`
  was already a dependency); the field is `live()` and shows the next three run times in plain
  language. Helper text states the expression is evaluated in `Africa/Algiers`.
- **Next run column** on the index derives the next run from the cron and `last_sent_at`; a
  schedule that is enabled but has never sent shows **"Never sent"** in warning colour and is
  filterable via the `enabled_never_sent` filter — the tell-tale of a wrong cron expression.
- **Multiple recipients**: `schedule_email` accepts a comma-separated list, validated by
  `App\Rules\EmailList`; `ExportJob` emails every address.
- **`report_type` freezes** once the definition has any run (`hasRuns()`), with an explanatory
  hint — the history must never mix two different reports under one name.
- **Run now** (row action, confirmable): reuses the same `ScheduledReportRunner` as the cron sweep
  (same last-completed-month window, parameters and recipients), updates `last_sent_at`, then
  redirects to the generated report's view page.
- **Deletion**: definitions soft-delete; run history is **kept** — `pending_exports.report_definition_id`
  is now `nullOnDelete` (migration `2026_08_16_000000_…`), so the audit of what was sent survives
  the definition. Not cascaded.
- **Failure alert**: `AlertType::ScheduledReportFailed` raised by
  `ScheduledReportFailedDetector` when the newest run of a definition failed — recipients
  Manager + Accountant by default, subject `Scheduled report failed: :name` with the failure
  timestamp in the body; seeded rules escalate from there. One subject per definition, latest
  failure only, branch-scoped.
- **Shared run factory**: the sweep command and Run now both go through
  `App\Services\Reporting\ScheduledReportRunner::runForDefinition()` (transaction, creates the
  `PendingExport`, dispatches the `ExportJob`, updates `last_sent_at`). Only it and
  `ReportResource` create runs.
- **Fillable bug fixed**: `report_definition_id` was missing from `PendingExport::$fillable`, so
  scheduled runs never persisted the FK and scheduled reports silently emailed nobody. Factory
  created runs masked it (factories bypass `$fillable`); the end-to-end email test through the real
  `ExportJob::handle()` covers the path now.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ReportDefinitionResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/ReportsSectionTest.php
docker compose exec app php artisan reports:run-scheduled --now="2026-09-07 08:00"
```

The `--now` option exists specifically to test cron matching without waiting — use it to prove a
definition fires when expected.

By hand: create a schedule due in the next minute, let the scheduler fire it, and confirm the file
arrives in Mailpit (http://localhost:8025) and that the run appears in the definition's history.
