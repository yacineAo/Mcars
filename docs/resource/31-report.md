# 31 — Report (Reports)

**Model:** `App\Models\PendingExport` · **Slug:** `/admin/reports` · **Status:** ✅ fine

Closes **REQ-16**. See [`../tasks/phase-09-reports.md`](../tasks/phase-09-reports.md) and the
Reports cluster in [`../02-filament-panels.md`](../02-filament-panels.md).

## What it is for

The single entry point for reports. One row is one report **run**: the parameters asked for, the
figures readable on its view page, and the file the queue produced from them. It replaced a
duplicated pair — a `ReportsHubPage` and a `PendingExportResource` that listed the same rows with
the same actions.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | type, scope, format, status, size, requested at; filters on type/format/status; `poll('10s')` |
| create | ✅ | sectioned: report / scope / period (with presets) / output format |
| view | ✅ | `content()` composes an infolist summary plus an array-backed table |
| edit | ❌ | **correct** — a run is a historical record; re-running makes a new row |
| row actions | ✅ | View, Download, Retry — via `recordActions()` |
| header / toolbar actions | ✅ | New report; on view: Download, Export as (PDF/Excel/CSV), Retry, Delete |
| relation managers | ❌ | none needed |
| `canAccess()` | ✅ | `reports.view_financials` |

This is the newest resource in the panel and the one built to the conventions deliberately.
Worth recording what it gets right, so it is not "tidied" later:

- **No edit page, by design.** Re-running is a new row via the `rerun` action on `ViewReport`, so
  the archive keeps one row per generated file.
- **Figures come from `ReportDataResolver` → `ReportService` only**, and `ExportJob` uses the same
  resolver — so the screen and the file cannot disagree about period, branch or scope.
- **Scoped query**: you see your own runs unless you hold `branches.view_all`, matching the rule
  `ExportController` already applies to the download itself.
- **Built from Filament schema/table components**, not hand-written markup — the panel loads
  Filament's compiled stylesheet with no custom theme, so bespoke utility classes would have no
  rules behind them.
- Uses `recordActions()`, not the deprecated `->actions()` — the only resource in the panel that
  does.

## Should be

### Index
As built. The `poll('10s')` is justified here, unlike on
[`13-transaction.md`](13-transaction.md): rows resolve themselves on the queue, so without polling
a user reloads to find out whether the file landed.

### Create
As built. The period section hides for receivables ageing, which is not periodic — that behaviour
comes from `ReportType::isPeriodic()`.

### View
As built: a live summary plus a detail table for the report types that have rows, and a collapsed
"Generated file" section carrying the snapshot's provenance. The page states that its figures are
recomputed now while the file is a snapshot, which is the distinction that would otherwise confuse
someone reopening an old run.

### Edit
Must not exist.

### Relations
None. A run is a leaf. The reverse direction — a saved definition's run history — belongs on
[`32-report-definition.md`](32-report-definition.md).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `ViewAction` | row | always | `reports.view_financials` | — | via `recordActions()` — the only resource using it |
| `download` | row + view header | `isCompleted()` | `reports.view_financials` + `ExportController` re-checks | — | correct |
| `retry` | row + view header | `isFailed()` | `reports.view_financials` | re-dispatches `ExportJob` | correct |
| `rerun_{format}` | view header group | always | `reports.view_financials` | creates a new run + `ExportJob` | new row per file — correct |
| `DeleteAction` | view header | always | `reports.view_financials` | — | safe: deletes an export, not ledger data |

## Gaps and risks

1. **✅ Locale plumbing landed.** `ExportJob::generatePdf` now sets the app locale to the
   requester's `User::locale` (restored in a `finally`, since queue workers persist between
   jobs) and passes `locale` + `Locale::direction()` into the view; the eight templates
   render `<html dir="{{ $direction }}">` instead of a hardcoded `dir="ltr"`. **Still open by
   design:** the Phase 9b item — Arabic RTL PDF *verification* — stays open because it needs
   font work; do not close the 9b item on the back of the plumbing.
2. **🟡 Seeded-fixture parity test added; timing test scheduled.** The parity test
   (`ReportsSectionTest`, "renders export totals that match the on-screen figures from a
   seeded fixture") posts a revenue, an expense and an inter-branch-clearing row through
   `AccountingService`, then asserts the resolver's output (what the view page renders)
   equals the numbers parsed out of the rendered CSV, XLSX and PDF files — the 2600
   exclusion is asserted in the file as well as on screen. The three-year export test in
   `Phase9Test` seeds 36 months of ledger rows and runs the job synchronously (what the
   worker runs) under a wall-clock bound; it is marked group `slow` and can be run in
   isolation with `./vendor/bin/pest tests/Feature/Phase9Test.php --group=slow`, while
   still running in the default suite at a few seconds' cost.
3. **🔵 Scope column lookups: decided — not worth batching.** `describeScope()` resolves the
   customer, car or owner a run was narrowed to, so a page of 25 scoped runs is up to 25
   primary-key lookups across three tables. The page is a cold archive that is not hot; a
   batched `whereIn` per entity type would save tens of milliseconds on a page that is
   browsed occasionally. Decision: leave as is.
4. **🔵 `ReportType::CashSessionAudit` is not obviously financial** in the way P&L is, yet the whole
   resource sits behind `reports.view_financials`. Defensible — a till variance is money — but if a
   supervisor ever needs the cash audit without seeing profit, that gate is where it will bite.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ReportsSectionTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
```

`ReportsSectionTest` already asserts: exactly one reports entry point, all 8 report types render
on the view page, the `reports.view_financials` gate refuses the pages themselves, the own-runs
query scope, resolver parity between screen and export, and a real non-empty file for all 8 types
× 3 formats. Keep those green.

By hand: run a P&L for last month and reconcile it against the dashboard. Then run the same report
as an Arabic user and confirm the PDF is Arabic and RTL — that is the check gap 1 is about.
