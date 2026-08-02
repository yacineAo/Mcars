# 30 — PayrollRun (HR)

**Model:** `App\Models\PayrollRun` · **Slug:** `/admin/payroll-runs` · **Status:** ✅ done

Closes **ADV-07**. Read [`../05-accounting-model.md`](../05-accounting-model.md) — payroll
posts salaries, and the run is the batch.

## What it is for

One month's payroll for a branch. It gathers the employees' items — base salary, commissions
earned, advances to recover — is approved, then paid, which posts the whole batch to the ledger.
It is the largest single posting the business makes each month.

## Decisions taken

- **A run is generated, not typed.** `PayrollService::generate(branchId, period)` creates the run
  and, in one transaction, gathers every active employee's base salary, their unrecovered
  advances (`employee_advances.recovered_in_payroll_item_id IS NULL` and still
  `outstanding`) and their unpaid commissions (`commissions.payroll_item_id IS NULL`, not
  cancelled) into one `payroll_items` row per employee. The screen only names the month; the
  branch is the acting user's own (same resolution `BelongsToBranch` applies). `gross` is the
  salary leg, `net = base + commissions − advances` — the poster splits E57/E59 at approval and
  E60 pays the net.
- **The sweep is claimed at generation.** The moment a commission or advance lands in a run its
  stamp (`payroll_item_id` / `recovered_in_payroll_item_id`) is set, so a second run can never
  gather it again — including a run for a *later* month while an earlier one is still draft.
  Removing a draft item (a legitimate correction) releases its stamps back to the queues via
  `PayrollService::unsweep()`, so nothing is buried with the line. A settled commission is sealed:
  paying the run marks its swept commissions `paid`, which the round-29 guard already refuses to
  amend.
- **The status moves only through the posting flow.** The form has no status field. The resource's
  `canEdit` freezes a run once approved (a draft keeps `period_month` and `notes` editable), and
  `PaymentService::approvePayroll()` / `payPayroll()` own the status flip, the approval/payment
  stamps and the item statuses (`pending → approved → paid`) inside the **same transaction and
  row lock as the posting** — a second caller (stale tab, import, double-click) finds the run
  already moved and is refused. Approving twice, paying a draft, paying twice: all
  `DomainException`, all tested.
- **One live run per branch and period.** A partial unique index
  (`payroll_runs_branch_period_unique`, `WHERE status <> 'cancelled'`, raw `DB::statement` —
  Laravel's `unique()->where()` does not emit a PG partial index) makes the DB refuse a duplicate
  before the service's own guard gets a word in. A cancelled run does not hold the slot, so a
  month can be re-generated after a mistake.
- **No delete path at all.** `DeleteBulkAction` is gone: once generated the run has claimed
  amounts from the sweep queues, once approved the month is on the ledger. There is no delete —
  not single, not bulk.
- **Totals are derived, never stored.** `PayrollService::runTotals()` sums the items (gross,
  commissions, advances, net) and the index shows the net total and the item count; the resource
  never sums money itself (docs/05).

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | period, branch, status badge, **derived net total** (`PayrollService::totalNetFor`), **employee count** (`counts('items')`), approval trail; filters: status (defaults to draft + approved), period, branch (pinned) |
| create | ✅ | `period_month` + notes only; `PayrollService::generate()` gathers the items; duplicate month refused as a field error; redirects to the view page |
| view | ✅ | **new** — period, branch, status, approval trail (approved_by/at, paid_at), derived totals (gross, commissions, advances, net), items + transactions relation managers |
| edit | ✅ | `period_month` + notes only while draft; frozen once approved (`canEdit`) |
| row actions | ✅ | `approve` (visible on Draft), `pay` (visible on Approved) — both behind `hr.manage`, both call the service that owns the transaction boundary; Edit |
| header / toolbar actions | ✅ | `CreateAction`; **no `DeleteBulkAction`** |
| relation managers | ✅ | **items** — employee, base, commissions, advances, net, status; edit/remove in a modal **only while draft** (edit recomputes gross/net from the edited terms); **transactions** — the run's ledger rows, strictly read-only, via `HasLedgerPostings` |
| `canAccess()` | ✅ | `hr.view_salary`; writes additionally `hr.manage` |

## Service

`App\Services\Payment\PayrollService`:

- `generate(int $branchId, string $period): PayrollRun` — duplicate guard, then gathers the
  employees/advances/commissions into items and claims the sweep stamps, one transaction.
- `runTotals(PayrollRun): array{gross, commissions, advances, net}` / `totalNetFor(PayrollRun)` —
  the derived figures the screens display.
- `unsweep(PayrollItem): void` — releases a removed draft item's commission and advance back to
  the queues.

`PaymentService::approvePayroll(PayrollRun, int $userId)` and `payPayroll(PayrollRun, int $userId)`
now own the transaction boundary, the row lock, the status guards (draft-only / approved-only) and
the status/stamp updates that the resource previously did inside its own `DB::transaction` —
closing the double-post at the invariant level rather than in a `visible()` closure. The pay flow
also marks the run's swept commissions `paid`.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/PayrollRunResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/CommissionResourceTest.php
```

By hand: generate a run for a month, confirm the items carry base salary, commissions and
advances; approve and confirm the accrual posts and the run freezes; pay and confirm the payable
clears, the items read `paid` and the swept commissions are sealed; try to generate the same
month again.
