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

1. **🟡 Queued PDFs ignore the requester's language.** `ExportJob` never calls
   `App::setLocale()`, so a PDF renders in the queue worker's default locale regardless of who
   asked for it. Related: `App\Enums\Locale::direction()` exists and is unused, and all eight
   templates under `resources/views/reports/pdf/` hardcode `<html dir="ltr">`. The fix is to pass
   the requesting user's locale and direction into the view from the job. **Scope note:**
   `phase-09-reports.md` defers Arabic RTL PDF verification to Phase 9b because it needs font
   work, so the locale plumbing can land now but the RTL rendering needs verifying separately —
   do not close the 9b item on the back of it.
2. **🟡 Two phase-9 test items remain open**, per `phase-09-reports.md`: exported totals matching
   the on-screen figures against a seeded fixture, and a three-year export completing on the queue
   without timing out. The resolver is shared, so parity is structural rather than accidental —
   but it is asserted only at the resolver level, not by comparing a rendered file's numbers to the
   screen.
3. **🔵 Scope column costs a lookup per row.** `describeScope()` resolves the customer, car or owner
   a run was narrowed to, so a page of 25 scoped runs is up to 25 extra queries. Acceptable for an
   archive that is not hot, and noted so it is a decision rather than a surprise.
4. **🔵 `ReportType::CashSessionAudit` is not obviously financial** in the way P&L is, yet the whole
   resource sits behind `reports.view_financials`. Defensible — a till variance is money — but if a
   supervisor ever needs the cash audit without seeing profit, that gate is where it will bite.

## Checklist

- [ ] Pass the requesting user's locale and `Locale::direction()` from `ExportJob` into the PDF views
- [ ] Replace `dir="ltr"` in the 8 templates under `resources/views/reports/pdf/`
- [ ] Add the seeded-fixture test comparing a rendered export's totals to the on-screen figures
- [ ] Add (or schedule) the long-range export timing test
- [ ] Decide whether the scope column's per-row lookups are worth batching

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
