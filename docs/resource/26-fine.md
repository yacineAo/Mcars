# 26 — Fine (Operations)

**Model:** `App\Models\Fine` · **Slug:** `/admin/fines` · **Status:** ✅ done

Closes **REQ-15**. See [`../05-accounting-model.md`](../05-accounting-model.md) — a fine
posts to the ledger once liability is settled (E49 customer, E50 company).

## What it is for

Traffic fines arrive weeks after the rental, addressed to the company, and someone has to decide
who pays: the customer who was driving, or the business. That decision is the whole screen. Once
made, the fine posts — as a receivable against the customer, or as an expense.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | defaults to the undecided queue; type / violation-date-range / car / customer filters |
| create | ✅ | car + offence time auto-suggest the booking and customer; total composed from `amount` + `late_penalty_amount` |
| view | ✅ | notice, money, liability decision trail, linked booking/customer/car, gated postings table |
| edit | ✅ | money, liability and customer frozen once posted; `canEdit()` refuses a posted fine |
| row actions | ✅ | `propose_liability`, `assign_liability`, View, Edit, single Delete — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` only — **no bulk actions** |
| relation managers | ✅ | Transactions (posting), strictly read-only, gated on `reports.view_financials` |
| `canAccess()` | ✅ | broad read on `fines.view`; assignment on `fines.manage` |

**The liability logic is correctly placed.** Both actions delegate to `FineLiabilityService`
(`FineResource.php:283` and `:312`) — `proposeLiability()` suggests, `confirmLiability()` decides
and posts. The suggestion is not computed in the resource, which is what
[`../02-filament-panels.md`](../02-filament-panels.md) promised and it holds. Deciding is a
single `db->transaction()` that locks the row, stamps `liability_determined_by_id` /
`liability_determined_at` / `liability_note` and posts E49 or E50 through `PaymentService` — a
crash cannot leave a decided row without its ledger entry (the service refuses a second decision:
`DomainException('already posted')`).

The schema supports the audit trail properly too: `liability_determined_by_id`,
`liability_determined_at` and `liability_note` record who decided and why.

## What was done (closing the gaps)

1. **`canAccess()` with two gates.** Reading the queue is broad — `fines.view` (super_admin,
   manager, accountant, receptionist, supervisor). Deciding who pays is `fines.manage`
   (super_admin, manager, receptionist, supervisor) and posts E49/E50: same reasoning as
   `reverse_transaction` — the accountant who answers for the books does not make the call that
   charges a customer, it audits the result. `canEdit` / `canDelete` check `fines.manage`, the
   posted freeze and `userCanReachBranch()`; the list query is branch-pinned server-side via
   `pinToAccessibleBranches()`.
2. **Bulk delete removed** — `->bulkActions([])`. A single `DeleteAction` remains, `visible()` /
   `disabled()` per row on `canDelete()`, which refuses once `isPostedToLedger()` — the
   receivable or the absorbed expense stays on the ledger and the only way back is a reversal.
3. **Edit frozen once posted.** `$frozen = isPostedToLedger()` disables car, customer, booking,
   contract, `violation_at` and both money inputs; `canEdit()` closes the page itself (verified
   `assertForbidden()` on the edit route). `liability` and `status` are **always** read-only —
   the decision is the `assign_liability` action's, which posts the ledger entry — with
   `CreateFine::mutateFormDataBeforeCreate()` / `EditFine::mutateFormDataBeforeSave()`
   re-asserting `pending_review` / the record's own values against a crafted payload (a
   `disabled()` field can be bypassed client-side, per the Filament vendor note). A row can
   never claim a decision the ledger did not record. `liability_note` and `notes` stay
   editable on an unposted fine.
4. **Index defaults to the undecided queue** — the `pending_liability` filter
   (`liability = pending_review`) is `->default(true)`, so the screen opens on what still needs
   a decision. New filters: `type`, a half-open `violation_at` range (timezone-safe, like
   BookingResource — a 00:30 offence must land on the right day), car and customer (both options
   pinned to accessible branches, not a `->relationship()` enumeration). The create form's car,
   customer, booking and contract selects are pinned the same way — a clerk never enumerates
   another branch's records. Columns: reference, notice number, car, type, violation date,
   total, liability, status.
5. **Booking auto-match in the service.** `FineLiabilityService::matchActiveBooking($carId,
   $violationAt)` finds the booking covering the offence instant by **time window only** (null
   `actual_return_at` = still rented = a hit) — the booking's *current* status is deliberately
   not consulted, because a fine usually arrives after the rental was checked in and the booking
   reads `completed`; a booking that never started has no `actual_pickup_at` and is excluded by
   the window itself (ADR-011 "active at that moment"). The create form's `car_id` /
   `violation_at` listeners call it and pre-fill `booking_id`, `customer_id`, `contract_id`;
   `proposeLiability()` proposes who pays — `Customer` on a hit, `Company` on a miss — while
   persisting only the pre-filled suggestion (matched booking + note); the fine's `status`
   stays `pending_review` and an unsaved fine is returned as-is, never persisted. The proposal
   is never a decision (ADR-011): only `confirmLiability()` commits, posting E49/E50 in the
   same transaction.
6. **`total_amount` is composed, never typed.** The create/edit form sums `amount` +
   `late_penalty_amount` through `Money` in `afterStateUpdated` and writes `total_amount`, which
   is `disabled()` (there is no way to type it) **and `->dehydrated()`** — without the latter the
   NOT NULL column would never receive the composed value and the create form could not save
   (DepositResource's status field is the same shape). A test saves a fine through the create
   page and asserts the persisted total, and an edit test asserts a recomposed total persists.
7. **View page.** Notice details, the three money fields, the liability decision (who decided,
   when, and the note), linked customer / booking / contract / car, and a History relation group
   with the ledger posting — `TransactionsRelationManager` extends
   `LedgerPostingsRelationManager` (strictly read-only, ADR-003) and is gated on
   `reports.view_financials`, so the receptionist who decided who pays does not audit the
   posting.
8. **Deprecated `->actions([...])` → `->recordActions([...])`.**
9. **Casts fixed.** `Fine` had no `casts()`: `status`, `liability` and every money/date field
   came back as raw strings. Added the full cast set plus generic `@return BelongsTo<X, $this>`
   annotations (repo convention from `OwnerInstallment`).

## Checklist

- [x] Add `canAccess()`; restrict liability assignment specifically
- [x] Remove `DeleteBulkAction`; guard single delete on being posted
- [x] Freeze amounts, liability and customer once posted
- [x] Default the index to pending liability; add type / date-range / car / customer filters
- [x] Auto-match the booking from `car_id` + `violation_at` in the service
- [x] Confirm `total_amount = amount + late_penalty_amount` is computed, not typed
- [x] Add a view page with the liability decision trail and a gated postings table
- [x] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FineResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

`FineResourceTest` (24 tests) covers the two access gates (plus a crafted assign call that
cannot even mount), the service suggestion (customer hit, company miss, a rental completed
since the offence, and an unsaved fine that stays undecided), **both posting-matrix outcomes** —
E49 (1120 / 2220, receivable) and E50 (5140 / 2220, absorbed expense) — the double-decision
refusal, the posted freeze, the delete rule and absence of bulk actions, the create-form
suggestion, total composition and an actual save through the create page, an edit that
recomposes and persists the total, the undecided default view and filters, the view page with
the gated postings relation manager, and fail-closed branch scoping (including a crafted
cross-branch delete that cannot resolve).

By hand: enter a fine, propose liability, confirm the suggestion is sensible, assign it to the
customer and confirm a receivable appears in the History tab. Then confirm the fine can no
longer be edited, and that the accountant sees the posting while the receptionist does not.
