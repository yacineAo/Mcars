# Phase 9 — Reports & Exports

**Status: ⬜** · Depends on: Phase 7 · Closes: **REQ-16**

Anything on screen can leave as a document.

## Read first
[`../03-service-layer.md`](../03-service-layer.md) §ReportService ·
[`../05-accounting-model.md`](../05-accounting-model.md) §Derivation queries

## Blocked on a business answer

**TVA / VAT treatment** — account 2400 exists and matrix row E03 posts to it, but Algerian rental VAT
rules must be confirmed with the accountant before the tax report is built. Everything else in this
phase can proceed without it.

## Deliverables

### Reports
- [ ] Profit & loss
- [ ] Expense report — by category, by car, by branch
- [ ] Customer report — activity + balances
- [ ] Fleet report — utilisation + profitability per car
- [ ] Tax report *(blocked, see above)*
- [ ] Cash flow
- [ ] Owner statements
- [ ] Receivables ageing
- [ ] Cash session audit

### Infrastructure
- [ ] `ReportsHubPage` with a parameter form per report
- [ ] **PDF** — branded, per-locale, **Arabic RTL correct** (Browsershot + the Arabic fonts installed
      in the app image in Phase 0)
- [ ] **Excel / CSV** — multiple sheets where useful
- [ ] **Queued generation** for large ranges, with a notification and download link when ready.
      Queued exports must carry `branch_id` in the **job payload** — there is no session on the queue,
      so a global scope resolves to nothing and silently produces the wrong data.
- [ ] Saved report configurations; optional scheduled email delivery
- [ ] Report headers state the branch, or "All Branches" — a PDF that does not say which branch it
      covers is unusable the moment it leaves the screen

### Correctness
- [ ] Reports read **only** through `ReportService` — no report may write its own aggregation, or
      "profit" acquires a second definition
- [ ] Company-wide totals **exclude inter-branch clearing** (account 2600)

## Tests

- [ ] Exported totals match the on-screen figures **exactly**
- [ ] Arabic PDFs render RTL correctly and are not boxes
- [ ] A 3-year export completes on the queue without timing out
- [ ] Report data respects the requester's role and branch scope
- [ ] A queued export produces the same figures as the interactive report

## Definition of done

Export last month's P&L to PDF and the fleet profitability report to Excel; both reconcile to the
dashboard. Gates green.
