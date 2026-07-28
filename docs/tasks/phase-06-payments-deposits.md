# Phase 6 — Payments, Deposits, Owner Instalments, Fines, Payroll

**Status: ✅ Done** · Depends on: Phases 4, 5 · Closes: **REQ-07**, **REQ-14**, **REQ-15**, **REQ-03**
(payment side), **ADV-07**

Every remaining money flow, recorded through the Phase 4 ledger.

## Read first
[`../05-accounting-model.md`](../05-accounting-model.md) — posting matrix rows **E10–E63** ·
[`../01-database-schema.md`](../01-database-schema.md) §Modules 4–5 ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-011

## Deliverables

### Tables
- [x] `payments`, `payment_schedules`
- [x] `deposits`, `deposit_deductions`
- [x] `owner_installments`
- [x] `fines`
- [x] `employees`, `payroll_runs`, `payroll_items`, `employee_advances`, `commissions`

### Enums
- [x] `PaymentMethod` — `cash`, `bank_transfer`, `ccp`, `card`, `baridimob`, `cheque`,
      **`compensation`** (netting where no money physically moves — deducting a cost from an owner's
      rent, applying a customer credit; it must still be posted or the balances never clear)
- [x] `PaymentDirection`, `PaymentStatus`, `PaymentScheduleStatus`, `DepositStatus`,
      `DeductionReason`, `InstallmentStatus`, `FineType`, `FineLiability`, `FineStatus`,
      `EmployeeStatus`, `ContractType`, `SalaryType`, `PayrollRunStatus`, `PayrollItemStatus`,
      `AdvanceStatus`, `CommissionStatus` — plus labels in all three locales

### Posters (matrix E10–E63)
- [x] `PaymentPoster` · `DepositPoster` · `OwnerInstallmentPoster` · `FinePoster` · `PayrollPoster`

### Services
- [x] **`DepositService`** — hold / deduct / refund / forfeit. **A deposit is a liability, never
      revenue.** Deductions cannot exceed the deposit; the excess becomes a receivable.
      `DeductionReason` maps to its credit account (see the table in `../07-enums.md`) so the mapping
      lives in one place.
- [x] **`OwnerStatementService`** — monthly instalment generation from active agreements (scheduled),
      payment recording. **Owner remaining balance is the balance of account 2200 filtered by
      `car_owner_id`** — never a column.
- [x] **`FineLiabilityService`** — match `violation_at` against contracts active for that car at that
      instant, **propose** liability with the matched contract; a human confirms (ADR-011). Automatic
      assignment of a legal liability is not acceptable.
- [x] Payroll: run creation, per-employee items, commission accrual from bookings, advance recovery,
      approve → pay

### Resources
- [x] `PaymentResource`, `PaymentScheduleResource` (overdue filter), `DepositResource` (+ deduction
      line items, reachable from the contract page), `OwnerInstallmentResource` (worklist by due
      date), `FineResource` (with the liability-suggestion action), `EmployeeResource`,
      `PayrollRunResource`, `EmployeeAdvanceResource`, `CommissionResource`
- [x] Un-stub the Financials and Fines tabs on `CustomerResource`

## The four things that are easy to get wrong

1. **A deposit is not revenue.** It credits *2100 Security Deposits Held*. Only a deduction converts
   part of it to revenue.
2. **A customer-liable fine touches neither revenue nor expense.** `Dr 1120 Fines Receivable /
   Cr 2220 Fines Payable`. It only hits P&L if written off.
3. **An employee advance is an asset** (1130), not a salary expense. Posting it to 5080 counts the
   salary twice.
4. **Owner rent accrual is stamped with `car_id`** (E32). That is what makes a third-party car's P&L
   read *revenue − owner rent − fuel − maintenance*.

## Tests

- [x] Every remaining posting-matrix row
- [x] A held deposit **never** appears in revenue
- [x] Deductions cannot exceed the deposit; the overflow lands in receivables
- [x] Owner balance = accrued − paid, read from account 2200
- [x] A customer-liable fine leaves profit unchanged until written off
- [x] An advance is an asset, not an expense
- [x] Sum of partial payments equals the invoice and the derived balance reaches **exactly** zero
- [x] An instalment plan of 100.00 over 3 totals exactly 100.00 (`Money::allocate`)

## Definition of done

Take a 40% deposit and two instalments on one rental; deduct damage from the deposit at return; pay an
owner's monthly rent; assign a speed-camera fine to the customer who had the car at that moment.
Gates green.
