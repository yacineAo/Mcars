# 23 — Deposit (Payments)

**Model:** `App\Models\Deposit` · **Slug:** `/admin/deposits` · **Status:** ✅ audited — fine

Closes **REQ-14**. Read [`../05-accounting-model.md`](../05-accounting-model.md) —
**a security deposit is a liability (account 2100), never revenue.**

## What it is for

Money held on the customer's behalf against damage, fines or fuel. Taken at checkout, drawn
down against actual charges, and returned. It is the one balance in the business that belongs
to someone else, which is why it is a liability and why the whole screen is built around not
letting it go negative.

## State after audit

| Surface | State | Notes |
|---|---|---|
| index | ✅ | default view is **outstanding** deposits (Held + PartiallyRefunded — the liability the business needs to see); status filter and a `held_at` range |
| remaining balance | ✅ | derived column, `amount − Σ deductions`, eager-loaded once per query via `withSum` — no stored balance column (ADR-003) |
| create | ✅ | `financial_account_id` added (cash in the till vs card pre-authorisation) |
| view | ✅ | infolist — amount held, remaining balance, status, booking/customer links, held/settled timeline — plus the deductions and postings tables |
| edit | ✅ | frozen once posted, notes only |
| row actions | ✅ | `hold`, `deduct`, `refund`, View, Edit — `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction`; **no bulk actions** — the `DeleteBulkAction` is gone |
| relation managers | ✅ | `DeductionsRelationManager` + `TransactionsRelationManager` — both read-only, gated `reports.view_financials` |
| `canAccess()` | ✅ | `reports.view_financials` (`DepositResource.php:44-47`) |

## What changed

1. **`DeleteBulkAction` removed.** A deposit whose liability is posted cannot be deleted
   without leaving account 2100 overstated against nothing — and there is no pre-posting
   window either, so no delete exists at all, row or bulk.
2. **View page added** — a deposit's meaning is its *history* (taken, drawn down twice,
   partially refunded), and that story now has a home. Sections: amount held and remaining
   balance, the booking and customer as links (only when the viewer can open the target — a
   dead link into a 403 is worse than plain text), the held/settled timeline with who settled
   it, then the deductions and ledger postings tables under a single History group.
3. **Remaining balance column** — the number the screen is about, derived as
   `amount − Σ deductions`. `getEloquentQuery()` eager-loads the sum once per query
   (`withSum`), never per row. Gated like every money figure.
4. **Outstanding default view** — the status filter defaults to Held + PartiallyRefunded:
   outstanding deposits are the liability the business needs to see; settled ones are
   history. A `held_at` date-range filter joins it.
5. **Edit freezes once posted.** `booking_id`, `customer_id`, `amount`, `method`,
   `financial_account_id` and `held_at` all disable behind `isPostedToLedger()`. The
   liability posting is already in `transactions` (append-only, ADR-003), so raising the
   amount afterwards would understate what the business owes. Notes stay editable.
6. **`DepositDeduction` construction moved into `DepositService::deductFromData()`.** The
   row's shape — including `created_by_id` — is assembled in the service, not in a Filament
   closure, so a second caller cannot record the evidence differently. The resource passes
   the form data and calls the service; the cap still lives in `deduct()`.
7. **Amounts labelled as held.** The list column is "Amount held" with a tooltip —
   "a liability owed back to the customer — never revenue" — never a bare "Amount" beside
   revenue figures elsewhere.
8. **`->actions([...])` → `->recordActions([...])`** and the deprecated bulk-actions alias
   are gone.
9. **`financial_account_id` select added to create** — cash held in the till is different
   from a card pre-authorisation, and the column existed in `deposits` without a form field
   or a model relation. Both now exist (`Deposit::financialAccount()`).

## Invariants held

- **The service owns the money rules.** Hold, deduct and refund all delegate to
  `DepositService`; the service refuses a deduction that would exceed the deposit, and the
  UI never decides what the ledger should say.
- **The deductions table cannot bypass the cap.** It is read-only in the relation-manager
  sense: rows are created by the `deduct` action alone, so no create form exists to write a
  deduction past the service's check.
- **No stored balance.** The remaining balance is derived from the deduction rows in the
  list and on the view page; the `deposits` table carries no `amount_refunded` or running
  balance column.
- **Posting is the freeze line.** An unposted deposit is editable (the liability posting
  has not happened yet); once the E22 row exists, everything except notes locks.
- **2100 is a liability, never revenue** — asserted by `LedgerWiringTest` and
  `DepositResourceTest`, which check the credit account type.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/DepositResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

`DepositResourceTest` (14 tests): the `reports.view_financials` access gate, the absence of
any delete, the outstanding default view (and showing all four statuses), the derived
remaining-balance column, the `held_at` range, the post-posting freeze (including a refused
amount edit), the hold/deduct/refund visibility pairs, the service-owned deduction row
(`created_by_id` included), the view page with its booking link, the `reports.view_financials`
gate on both relation managers, the read-only deductions table, and the E22 posting to 2100
as a liability.

By hand: take a deposit, deduct twice, attempt a third deduction exceeding the remainder and
confirm the service refuses with a readable message. Confirm the remaining balance shown
equals amount less deductions, and that a partial deduction leaves the status
`PartiallyRefunded`.
