# Phase 4 — Accounting Ledger & Cash Register

**Status: ✅ Done** · Depends on: Phases 1–2 · Closes: **REQ-08**, **REQ-09**, **REQ-10** (engine)

> ## ⚠ Highest-risk phase in the build
>
> **Read [`../05-accounting-model.md`](../05-accounting-model.md) in full before writing a line.**
> Everything downstream records money through what is built here. Mistakes made now are corrected by
> restating history, which ADR-003 makes deliberately hard.

**Moved ahead of bookings on purpose.** Bookings take deposits and issue invoices — they need a ledger
that already exists. The ledger has no dependency on bookings: `booking_id`, `contract_id` and
`car_id` are nullable dimensions, so this phase is fully testable against expenses alone.

## Blocked on a business answer

**Revenue recognition** — the design books rental revenue at **contract activation (pickup), for the
full contracted amount**. Confirm with the accountant *before starting*. Also decide whether
depreciation on company-owned cars (matrix row E48) is in scope, or owned and third-party cars will
not be comparable in profitability reports.

## Deliverables

### Tables
- [x] `chart_of_accounts` — `code`, `type`, `parent_id`, `normal_balance`, `is_cash_equivalent`,
      `is_postable`, `is_system`, `branch_id`
- [x] **`transactions`** — `debit_account_id` + `credit_account_id` + positive `amount`, plus the
      dimensions (`branch_id`, `car_id`, `booking_id`, `contract_id`, `customer_id`, `car_owner_id`,
      `employee_id`, `expense_category_id`), provenance (`source_type`/`source_id`, `created_by_id`,
      `cash_session_id`) and reversal links
- [x] `financial_accounts`, `expense_categories`, `expenses`, `cash_sessions`
- [x] **`cash_register_entries` as a VIEW** over `transactions` filtered to cash-equivalent accounts
      (ADR-008), with a read-only Eloquent model. **Not a table** — that would be a second source of
      truth for cash.

### Constraints and invariants
- [x] `CHECK (amount > 0)`; `CHECK (debit_account_id <> credit_account_id)`
- [x] **PostgreSQL trigger blocking `UPDATE` and `DELETE` on `transactions`** (ADR-003)
- [x] Eloquent model guards refusing update/delete, and a call-context flag so only
      `AccountingService` may create
- [x] Policy denying create/update/delete from every UI path
- [x] Partial unique index: one `open` cash session per financial account
- [x] Indexes from [`../01-database-schema.md`](../01-database-schema.md), including
      `(branch_id, occurred_on)` and `(car_id, occurred_on)`

### Seeders
- [x] Full chart of accounts (1xxx–5xxx) from [`../05-accounting-model.md`](../05-accounting-model.md)
- [x] Expense categories mapped to COA accounts, covering every category in REQ-08
- [x] Default financial accounts per branch — **cash accounts are per branch**
      (`1010-MAIN`, `1010-ORAN`, …), or "cash on hand" is meaningless per location

### Services
- [x] **`AccountingService`** — `post()`, `postMany()` (atomic, for multi-leg entries sharing a
      `group_uuid`), `reverse()`, `balanceOf()`. Thin; the account knowledge lives in Posters.
- [x] Posters: `ExpensePoster`, `MaintenancePoster`, `CashSessionPoster`
- [x] **`CashRegisterService`** — balance from the ledger, open/close session, physical count,
      **variance posted as a real transaction** (E68/E69) so the ledger reconciles to the drawer

### Resources & pages
- [x] **`TransactionResource` — view-only.** No create/edit/delete action exists on it *at all*: not
      hidden, not permission-gated, absent. Row action: Reverse, with a mandatory reason.
- [x] `ExpenseResource` (draft → pending_approval → approved → paid), `ExpenseCategoryResource`,
      `FinancialAccountResource`, `ChartOfAccountResource`, `CashSessionResource`
- [x] **`CashRegisterPage`** — live balance, today's movements with reason and operator (REQ-09),
      open/close/count, transfer between accounts

### Jobs
- [x] Recurring expense generator (office rent, internet, electricity) → `pending_approval` + alert
- [x] Nightly integrity checks from [`../05-accounting-model.md`](../05-accounting-model.md):
      trial balance, cash reconciliation, deposits, receivables, owner payables, immutability,
      **orphan dimensions** (a car-related expense with `car_id IS NULL` silently corrupts per-car P&L)

### Retro-wire Phase 2
- [x] Completed maintenance logs and renewed car documents now post expenses stamped with `car_id`.
      Find them by the `// PHASE-4:` markers.

## Tests

- [x] Every posting-matrix row reachable in this phase (expenses, cash, transfers, variance)
- [x] `Transaction::create()` called outside `AccountingService` **throws**
- [x] `UPDATE` and `DELETE` on `transactions` raise **at the database level**
- [x] `reverse()` restores the prior balance exactly
- [x] Cash balance = opening float + movements, computed from the ledger
- [x] A closed session with a discrepancy posts the variance and raises an alert
- [x] An expense on a car-related category without `car_id` is rejected

## Definition of done

Open a register with a float, record a fuel expense against a car, close the register with a
deliberate 500 DZD discrepancy, and show the variance posted, attributed and flagged. Gates green.
