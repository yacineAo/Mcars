# 23 — Deposit (Payments)

**Model:** `App\Models\Deposit` · **Slug:** `/admin/deposits` · **Status:** 🟡 partial

Closes **REQ-14**. Read [`../05-accounting-model.md`](../05-accounting-model.md) —
**a security deposit is a liability (account 2100), never revenue.**

## What it is for

Money held on the customer's behalf against damage, fines or fuel. Taken at checkout, drawn
down against actual charges, and returned. It is the one balance in the business that belongs
to someone else, which is why it is a liability and why the whole screen is built around not
letting it go negative.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | |
| view | ❌ | see Relations |
| edit | ✅ | nothing frozen |
| row actions | ✅ | `hold`, `deduct`, `refund`, plus Edit — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | **`DeleteBulkAction`** |
| relation managers | ❌ | none, though `DepositDeduction` rows hang off it |
| `canAccess()` | ✅ | `reports.view_financials` (`DepositResource.php:44-47`) |

**This is the best-guarded money screen in the panel.** It is gated; `deduct` delegates to
`DepositService::deduct()`; and the invariant lives in the service, not the UI — with a comment
saying so explicitly (`DepositResource.php:127-129`): *"The service refuses a deduction that
would exceed the deposit, so the operator sees why rather than creating a negative balance."*
The `visible()` guards check both `isPostedToLedger()` and status
(`Held`, `PartiallyRefunded`), which is the correct pair.

Also confirmed: the `deposits` table has **no `amount_refunded` or running-balance column** —
the remaining balance is derived from the deduction rows, as it should be.

## Should be

### Index
Extend the filters with a date range on `held_at` and a **"held"** default view — outstanding
deposits are the liability the business needs to see; settled ones are history.

Add a derived **remaining balance** column (amount less deductions), gated. It is the number
the screen is about and it is not currently shown. Derive it — do not add a column to the
table.

Label the money clearly. A deposit column headed only "Amount" beside revenue figures
elsewhere invites the misreading the accounting model exists to prevent; the view page and
reports already say "a liability owed back to the customer", and the list should too.

### Create
Deposits should normally be created from the booking checkout, not typed here. `booking_id`,
`customer_id` and `amount` are the substance; `financial_account_id` matters because cash held
in the till is different from a card pre-authorisation.

### View
**Add one.** This is the resource where a view page is most clearly missing: a deposit's
meaning is its *history* — taken, drawn down twice, partially refunded — and that story is
currently unreadable anywhere in the panel.

Sections: amount held, remaining balance, status, the booking and customer as links, held/settled
timestamps and who settled it. Then the deduction table below.

### Edit
Freeze everything once posted to the ledger. `amount` in particular: the liability posting is
already in `transactions`, so raising the deposit afterwards makes the ledger understate what
the business owes. Notes only.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `deductions` (`DepositDeduction`) | **view** | yes — deductions are made by the action | `reports.view_financials` | date, reason, amount, description, created by |
| `transactions` | **view** | **yes, strictly** | `reports.view_financials` | reference, date, debit, credit, amount |

The deductions table must be read-only in the relation-manager sense: rows are created by the
`deduct` action so the service's cap is enforced. A relation manager with its own create form
would bypass that check entirely — this is the one place on this screen where an
innocent-looking "add" button would break the invariant.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `hold` | row | see resource | `reports.view_financials` | `DepositService` | posts the liability to 2100 |
| `deduct` | row | posted **and** `Held`/`PartiallyRefunded` | `reports.view_financials` | `DepositService::deduct()` | service refuses exceeding the deposit — correct |
| `refund` | row | see resource | `reports.view_financials` | `DepositService` | returns the balance |
| `EditAction` | row | always | `reports.view_financials` | — | must freeze once posted |
| `DeleteBulkAction` | toolbar | always | `reports.view_financials` | — | **remove** — gap 1 |

## Gaps and risks

1. **🔴 `DeleteBulkAction` on deposits** (`DepositResource.php:180`). A deposit whose liability
   is posted cannot be deleted without leaving account 2100 overstated against nothing.
   Remove it.
2. **🟡 No view page**, so the deduction history has nowhere to live — see View.
3. **🟡 Remaining balance not shown anywhere.**
4. **🟡 Nothing frozen after posting** — same pattern as [`22-payment.md`](22-payment.md) gap 3.
5. **🟡 The `deduct` action builds a `DepositDeduction` in the closure**
   (`DepositResource.php:126-133`). Milder than the equivalent in
   [`18-booking.md`](18-booking.md) gap 1, because the service owns the rule that matters — but
   the row's shape (including `created_by_id`) is still assembled in the UI layer, so a second
   caller could build it differently. Pass the form data to the service and let it construct.
6. **🟡 Deprecated `->actions([...])`.**
7. **🔵 Deposit amounts not labelled as a liability** in the list — see Index.

## Checklist

- [ ] Remove `DeleteBulkAction`
- [ ] Add a view page: balance, status, links, and the deduction + postings tables
- [ ] Add a derived remaining-balance column, gated
- [ ] Default the index to held deposits; add a `held_at` range
- [ ] Freeze all fields except notes once posted
- [ ] Move `DepositDeduction` construction into `DepositService`
- [ ] Ensure the deductions relation manager has **no** create/edit/delete
- [ ] Label deposit amounts as held-on-behalf in the list
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php
docker compose exec app ./vendor/bin/pest tests/Feature/AccountingLedgerTest.php
```

The posting-matrix test for deposits must assert the deposit lands in **2100 as a liability**
and never in a revenue account — that is the invariant this whole resource protects.

By hand: take a deposit, deduct twice, attempt a third deduction exceeding the remainder and
confirm the service refuses with a readable message. Confirm the remaining balance shown equals
amount less deductions, and that a partial refund leaves the status `PartiallyRefunded`.
