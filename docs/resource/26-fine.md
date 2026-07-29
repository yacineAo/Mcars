# 26 — Fine (Operations)

**Model:** `App\Models\Fine` · **Slug:** `/admin/fines` · **Status:** 🟡 partial

Closes **REQ-15**. See [`../05-accounting-model.md`](../05-accounting-model.md) — a fine
posts to the ledger once liability is settled.

## What it is for

Traffic fines arrive weeks after the rental, addressed to the company, and someone has to decide
who pays: the customer who was driving, or the business. That decision is the whole screen. Once
made, the fine posts — as a receivable against the customer, or as an expense.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | |
| view | ❌ | see Relations |
| edit | ✅ | nothing frozen after posting |
| row actions | ✅ | `propose_liability`, `assign_liability`, Edit — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none |
| `canAccess()` | ❌ | absent |

**The liability logic is correctly placed.** Both actions delegate to `FineLiabilityService`
(`FineResource.php:88` and `:109`) — `proposeLiability()` suggests, `confirmLiability()` decides
and posts. The suggestion is not computed in the resource, which is what
[`../02-filament-panels.md`](../02-filament-panels.md) promised and it holds.

The schema supports the audit trail properly too: `liability_determined_by_id`,
`liability_determined_at` and `liability_note` record who decided and why.

## Should be

### Index
Extend the filters with **pending liability** as the default view — the queue someone works
through when the post arrives — plus `type`, a `violation_at` range, and car and customer
filters. Show the notice number, car, violation date, amount, liability and status.

The gap between `violation_at` and `received_at` matters operationally: a fine received after the
customer has gone is much harder to collect, so surfacing the lag helps.

### Create
Fines are entered by hand from a paper notice, so this form is a genuine data-entry screen — one
of the few here. `car_id` plus `violation_at` should look up the booking that was active at that
moment and pre-fill `booking_id` and `customer_id`; that matching is the tedious part of the job
and belongs in the service, surfaced here as a suggestion the clerk confirms.

`amount` and `late_penalty_amount` are separate columns and `total_amount` is their sum — confirm
that sum is computed in a service and not typed, or it will drift.

### View
Worth adding: the notice details, the liability decision with who made it and the note, the
booking and customer as links, and the ledger posting. A disputed fine is exactly the record
someone needs to read in full.

### Edit
**Freeze once liability is assigned and posted.** `amount`, `total_amount`, `liability` and the
customer must become read-only — the posting has already created a receivable or an expense from
those values. A wrong liability decision is corrected by reversing the posting, not by editing
the row.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `transactions` | **view** | **yes, strictly** | `reports.view_financials` | reference, date, debit, credit, amount |

The fine's own history (proposed → assigned) is on the row, not a relation.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `propose_liability` | row | `! isPostedToLedger()` | **nothing** | `FineLiabilityService::proposeLiability()` | suggestion logic correctly in a service |
| `assign_liability` | row | see resource | **nothing** | `FineLiabilityService::confirmLiability()` | decides **and posts** — must be gated, gap 1 |
| `EditAction` | row | always | **nothing** | — | must freeze once posted |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 2 |

## Gaps and risks

1. **🔴 No `canAccess()`.** Assigning liability decides whether a customer is charged, and it
   posts to the ledger. That is not an every-role action. Reading fines can be broad; assigning
   liability should not be.
2. **🔴 `DeleteBulkAction` on posted fines.** Same shape as the rest of the money group: the
   receivable or expense stays in the ledger, the fine does not.
3. **🟡 Nothing frozen after posting** — see Edit. Part of the cross-panel pattern recorded as
   finding 10 in [`README.md`](README.md).
4. **🟡 No pending-liability default view** despite the whole screen existing to clear that queue.
5. **🟡 No booking auto-match** from car + violation time — see Create.
6. **🟡 `total_amount` composition unverified** — see Create.
7. **🟡 Deprecated `->actions([...])`.**

## Checklist

- [ ] Add `canAccess()`; restrict liability assignment specifically
- [ ] Remove `DeleteBulkAction`; guard single delete on being posted
- [ ] Freeze amounts, liability and customer once posted
- [ ] Default the index to pending liability; add type / date-range / car / customer filters
- [ ] Auto-match the booking from `car_id` + `violation_at` in the service
- [ ] Confirm `total_amount = amount + late_penalty_amount` is computed, not typed
- [ ] Add a view page with the liability decision trail and a gated postings table
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

A posting-matrix test must cover both liability outcomes: customer-liable posts a receivable,
company-liable posts an expense. Those are different rows in the matrix and both need asserting.

By hand: enter a fine, propose liability, confirm the suggestion is sensible, assign it to the
customer and confirm a receivable appears. Then confirm the fine can no longer be edited.
