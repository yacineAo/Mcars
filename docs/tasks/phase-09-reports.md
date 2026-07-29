# Phase 9 — Reports & Exports

**Status: ✅ (Phase 9a — infrastructure + interactive reports)** · Depends on: Phase 7 · Closes: **REQ-16**

> **Phase 9b — saved schedules and tax report** remains pending:
> - Saved report configurations with scheduled email delivery (`ReportDefinition`)
> - Tax report (blocked on accountant confirmation of VAT rules)
> - Browsershot PDF rendering (requires Node.js + Puppeteer in the app image)
> - Arabic RTL PDF verification

Anything on screen can leave as a document.

## Read first
[`../03-service-layer.md`](../03-service-layer.md) §ReportService ·
[`../05-accounting-model.md`](../05-accounting-model.md) §Derivation queries

## Blocked on a business answer

**TVA / VAT treatment** — account 2400 exists and matrix row E03 posts to it, but Algerian rental VAT
rules must be confirmed with the accountant before the tax report is built. Everything else in this
phase can proceed without it.

## Deliverables

### Reports (data via `ReportService`, export-ready)
- [x] Profit & loss
- [x] Expense report — by category, by car, by branch
- [x] Customer report — activity + balances
- [x] Fleet report — utilisation + profitability per car
- [ ] Tax report *(blocked, see above)*
- [x] Cash flow
- [x] Owner statements
- [x] Receivables ageing
- [x] Cash session audit

### Infrastructure
- [x] `ReportResource` — the single Reports entry point (`/admin/reports`): parameter form,
      on-screen figures, and the generated file, one run per row. `ReportDefinitionResource` nests
      under it in the navigation.
- [x] `ReportDataResolver` + `ReportRequest` — the one mapping from (report type, parameters) to a
      `ReportService` call. The view page and `ExportJob` both go through it, which is what makes
      "exported totals match the on-screen figures" a property of the code rather than a habit.
- [x] **PDF** — using `barryvdh/laravel-dompdf` (Browsershot deferred: needs Node.js + Puppeteer in the image)
- [x] **Excel / CSV** — using `maatwebsite/excel`; multi-sheet for fleet and customer reports
- [x] **Queued generation** for large ranges, via `ExportJob` + `PendingExport` model
      Queued exports carry `branch_id` in the **job payload** — there is no session on the queue,
      so a global scope resolves to nothing and silently produces the wrong data.
- [ ] Saved report configurations; optional scheduled email delivery *(deferred to Phase 9b)*
- [x] Report headers state the branch, or "All Branches" — the PDF/Excel output states its scope

### Correctness
- [x] Reports read **only** through `ReportService` — no report may write its own aggregation, or
      "profit" acquires a second definition
- [x] Company-wide totals **exclude inter-branch clearing** (account 2600)

## Tests

- [ ] Exported totals match the on-screen figures **exactly** *(requires a seeded fixture)*
- [ ] Arabic PDFs render RTL correctly and are not boxes *(deferred to Phase 9b)*
- [ ] A 3-year export completes on the queue without timing out *(manual integration test)*
- [x] Report data respects the requester's role and branch scope
- [x] A queued export produces the same figures as the interactive report *(tested via ReportService delegation)*

## Definition of done

Export last month's P&L to PDF and the fleet profitability report to Excel; both reconcile to the
dashboard. Gates green.
