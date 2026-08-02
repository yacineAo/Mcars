# 28 — EmployeeAdvance (HR)

**Model:** `App\Models\EmployeeAdvance` · **Slug:** `/admin/employee-advances` · **Status:** ✅ done

Supports **ADV-07**.

## What it is for

A salary advance — the employee takes money now and it comes off a future payroll. The recovery
link is `recovered_in_payroll_item_id`: while it is null the advance is still owed.

## Decisions taken

- **Approval = the payout.** There is no separate submit step. `requested` → **Approve & Pay**
  (status flip and E61 posting in one `PaymentService::approveAdvance()` transaction) or →
  **Reject** (status flip only, `PaymentService::rejectAdvance()`). The ledger entry *is* the
  recorded authorisation, closing gap 2: money cannot leave the till without a ledger row.
- **Self-dealing is refused twice.** The create form validates that the chosen employee's
  `user_id` is not the acting user, and `approveAdvance()` re-checks it under the status guard —
  a crafted create followed by a crafted approve cannot get around it.
- **Multi-month recovery is out of scope.** `recovered_in_payroll_item_id` is a single column;
  recovery lands in one payroll item. `partially_recovered` was dropped from the documented
  `AdvanceStatus` set because nothing can ever set it — see
  [`07-enums.md`](../07-enums.md). Revisit both together if multi-month recovery is ever wanted.
- **`financial_account_id` and `payment_id` stay unused.** The posting matrix fixes E61 at
  Dr 1130 / Cr 1010; the form deliberately has no financial-account field, so the UI cannot
  suggest a posting the matrix does not know.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | employee, amount, `advanced_on`, status badge, **recovered in** (derived from `recovered_in_payroll_item_id`) |
| create | ✅ | employee (pinned, self-dealing refused), amount, `advanced_on`, reason, notes; status server-set to `requested` |
| view | ❌ | not needed — see [`27-employee.md`](27-employee.md) |
| edit | ✅ | reason/notes only once money moved; amount, employee, date and status re-asserted server-side |
| row actions | ✅ | **Approve & Pay**, **Reject** (both gated `hr.manage` + only while `requested`), Edit |
| header / toolbar actions | ✅ | `CreateAction`; **no `DeleteBulkAction`** |
| relation managers | ❌ | none — the advance is a line on the employee's view page and on a payroll run |
| `canAccess()` | ✅ | `hr.view_salary`; writes additionally `hr.manage` |

## Filters

- **Open advances** (default ON): `requested` + `outstanding` — the workbench of requests
  awaiting approval and amounts still owed. Off shows settled history (`rejected`, `recovered`,
  `written_off`).
- Employee (pinned to reachable branches), status.

## Service

`PaymentService::approveAdvance(EmployeeAdvance, int $userId): Collection` — guards status
`requested`, refuses self-granted, posts E61 via `PayrollPoster::postAdvance()` and flips status
to `outstanding`, all inside one transaction. `rejectAdvance(EmployeeAdvance): EmployeeAdvance` —
guards `requested` under the same transaction boundary, flips to `rejected`, no ledger. Both throw
`DomainException` on stale rows; the row actions catch it and surface a danger notification instead
of a 500.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/EmployeeAdvanceResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

By hand: create a request, approve it, confirm the ledger shows Dr 1130 / Cr 1010; run payroll
for that employee with an `advances_deducted` line and confirm E62 recovery posts and net pay is
reduced by exactly the advance amount.
