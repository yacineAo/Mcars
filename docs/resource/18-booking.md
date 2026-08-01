# 18 — Booking (Bookings)

**Model:** `App\Models\Booking` · **Slug:** `/admin/bookings` · **Status:** ✅ audited — fine

Closes **REQ-05**, **REQ-06**, **ADV-01**. See
[`../tasks/phase-05-bookings-contracts.md`](../tasks/phase-05-bookings-contracts.md).

## What it is for

The busiest screen in the system. A receptionist lives here: quoting a rental, confirming
it, handing over keys, taking the car back, taking money. A booking is also the hub the
rest of the data hangs off — its contract, condition reports, extras, deposits and fines —
which is why the missing view page matters more here than anywhere else in the panel.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | eager-loaded, branch-pinned, 7 filters, full customer name, overdue rows marked |
| create | ✅ | a `Wizard` — Customer & Car, then dates/pricing, then Additional Drivers repeater |
| view | ✅ | identity, customer/car, expected vs actual dates, pricing, **settlement** (gated) |
| edit | ✅ | car, customer, pickup and pricing frozen once revenue is posted |
| row actions | ✅ | confirm, checkout, checkin, **extend**, cancel, record_payment, View, Edit, Delete |
| header / toolbar actions | ✅ | `CreateAction`; **no bulk actions** |
| relation managers | ✅ | 7 — contract, condition reports, extras, drivers, deposits, fines, payments |
| `canAccess()` | ✅ | `bookings.view` to read, `bookings.operate` to work the booking |

**The lifecycle actions are the best-built part of the panel and should be left alone.**
`confirm`, `checkout`, `checkin` and `cancel` each delegate to `BookingService`
(`BookingResource.php:151-257`), with a comment recording why: they used to be bare status
updates, so a car could be handed over without the rental ever reaching the ledger. The
`visible()` closures compare cast enums properly (`$record->status->is(...)`), which is
the bug ContractResource still has.

## What was fixed

### Index

The working queue it should have been. `returns_due_today`, `pickups_today` and `overdue`
toggles (the two questions a receptionist asks all day, plus the chase list), a `pickup_at`
range, and `SelectFilter`s on car and pickup branch — the branch control appearing only for
`branches.view_all`, with the query pinned server-side in `getEloquentQuery()` regardless of
what is submitted.

`customer.first_name` became the **full name** through `Customer::displayName()` (company
bookings fall back to the company, then the phone), and it is searchable across first name,
last name and company. Overdue rows are marked in red, bold, with a warning icon — they used
to be indistinguishable from active ones.

`getEloquentQuery()` eager-loads `car` and `customer`: the two relations every row renders
were firing 50 extra queries on a default page of 25, on the busiest screen in the app.

### Create

The wizard was already the right shape and is unchanged.

`is_blacklisted` is still **not** checked here — see [`09-customer.md`](09-customer.md)
gap 1. It stays that resource's call; nothing in this pass made it worse.

### View

The hub screen. Identity, customer and car, dates with **actual beside expected** (whether a
car went out and came back when it should have cannot be read from either column alone),
and the pricing breakdown.

The **Settlement** section — invoiced, paid to date, outstanding — comes from
`ReportService::bookingSettlement()`, aggregated over the ledger on every render. There is
no `bookings.paid_amount` and there must never be one. `outstanding` is the AR movement for
the booking rather than `invoiced − paid`, so a refund or a correcting reversal lands in it
without special casing. Gated on `reports.view_financials`; the contracted `total_amount`
stays ungated on the row because a receptionist needs it to take payment at all.

### Edit

Frozen once `checkout` has posted revenue: `car_id`, `customer_id`, `pickup_at`,
`expected_return_at` and every pricing field. The ledger rows referencing them are
append-only and cannot follow an edit, so changing them would desynchronise the two silently.
The fields are `disabled()`, which also means they are not dehydrated — a test submits a
changed price and car against a checked-out booking and asserts the stored values are
untouched, because the disabled attribute alone proves nothing.

Notes and additional drivers stay editable for the life of the booking.

### Extending a rental

A new `extend` action, because moving `expected_return_at` on the form would hand the
customer the extra days for nothing — revenue was posted at pickup for the amount contracted
then, and no edit recomputes it. `BookingService::extend()`:

- refuses anything not `Active`/`Overdue`, and refuses a date at or before the current one;
- **re-reads the booking under `lockForUpdate()` inside the transaction** and takes the
  current return date from that row. Two offices extending the same booking concurrently
  would otherwise both compute from the same starting date, both pass every check, and both
  post E72 — the rental extended once, the extra days invoiced twice;
- **asks `BookingAvailabilityService`** before saving. This is for the error message, not
  for safety: the `EXCLUDE` constraint *does* cover the UPDATE — verified, a bare
  `expected_return_at` bump across another booking's window fails with SQLSTATE 23P01
  `bookings_no_overlap`. A row never conflicts with itself, so owning its own row costs the
  constraint nothing. The check exists so the receptionist gets a sentence they can act on
  instead of a driver exception; the constraint is what actually makes double-booking
  impossible (ADR-002);
- prices the extra days at the booking's **own** `daily_rate` (the customer is extending the
  contract they signed, not buying at today's list price), through `Money`. Any started day
  is charged whole;
- posts **matrix E72** for the difference, dated the day the extension is agreed, carrying
  the day count and the stated reason in the row's `meta`. Earlier drafts appended that
  sentence to `bookings.notes` — free text staff can type over, so not an audit trail.

`BookingAvailabilityService::extend()` was **deleted**. It had no callers, did money
arithmetic in float, never checked availability, and raised `total_amount` without posting
anything — the extra days were invoiced nowhere.

### Relations

`Booking` has: `contract` (hasOne), `extras`, `conditionReports`, `additionalDrivers`,
`deposits`, `fines`.

All seven exist.

| Relation | Read-only | Gate | Notes |
|---|---|---|---|
| `contract` | yes | — | number, status, terms version, signed at + link out |
| `conditionReports` | yes | — | direction, odometer, fuel, clean, damage count |
| `extras` | **no** | `bookings.operate` **and** un-invoiced | see below |
| `additionalDrivers` | **no** | `bookings.operate` | editable for the whole life of the booking |
| `deposits` | yes | `reports.view_financials` | amount, method, status, held/settled |
| `fines` | yes | — | notice number, violation date, amount, liability |
| `payments` | yes | `reports.view_financials` | via the `payable` morph |

`Booking::payments()` was added as a `morphMany` — a payment points at its booking through
`payable`, because the same table also carries owner and payroll payments.

**Extras stop being editable at checkout**, and that is enforced in the model, not the form.
`extras_total` is posted to the ledger at checkout (matrix E04), so a line added afterwards
would show on the booking and charge the customer nothing. `BookingExtra` refuses the write
in `saving`/`deleting` — before the row exists, so a refusal leaves nothing behind — and
`BookingService::syncExtrasTotals()` refuses the same booking independently. The relation
manager also hides the buttons, but a UI that declines to offer an action is not the rule
being enforced: an import, a console write or a second screen reaches the table without
passing that form. Extras discovered after handover belong on the closeout.

**`extras_total` is derived from the lines, never typed.** The wizard field is disabled:
every create / edit / delete of an extras line funnels through
`BookingService::syncExtrasTotals()`, which recomputes `extras_total` from the line totals
and moves the difference into `total_amount`, so the two columns and the lines can never
disagree.

**The line total is derived too.** `quantity`, `unit_price` and `total` were three free-text
fields with nothing reconciling them — and `total` is what reaches `extras_total` and E04, so
a fat-fingered figure was invoiced exactly as typed. The field is now disabled and computed
by `BookingService::priceExtraLine()`, which both relation-manager actions route through.

Condition reports are read-only by design: a report is the evidence a damage charge is
argued from, so it is written by the check-out/check-in actions and never edited afterwards
from this screen.

### Actions

Every action is gated on `bookings.operate` as well as its status rule. Filament's actions
carry no authorization of their own and a row action runs in place over Livewire without
visiting a page, so each one re-checks — a test calls `confirm` as an accountant and asserts
the booking stays `Draft`.

| Action | Placement | Visible when | Delegates to | Notes |
|---|---|---|---|---|
| `confirm` | row | `Draft` \| `Pending` | `BookingService::confirm()` | |
| `checkout` | row | `Confirmed` | `BookingService::checkOut()` | posts revenue (E02/E04) |
| `checkin` | row | `Active` \| `Overdue` | `BookingService::checkInWithCharges()` | posts closeout |
| `extend` | row | `Active` \| `Overdue` | `BookingService::extend()` | reprices, posts E72 |
| `cancel` | row | `! status->isTerminal()` | `BookingService::cancel()` | takes a reason; reverses every open E02–E08 row (matrix E09) |
| `record_payment` | row | not `Cancelled`/`Draft` | `PaymentService::recordBookingPayment()` | service builds the `Payment` |
| `ViewAction` | row | always | — | the hub screen |
| `EditAction` | row | `bookings.operate` | — | frozen fields after checkout |
| `DeleteAction` | row | `Draft` only | — | anything invoiced is cancelled, not deleted |

### Permissions

A third bookings permission, `bookings.operate` (SuperAdmin, Manager, Receptionist,
Supervisor), seeded by migration as well as `RolePermissionSeeder`.

`bookings.manage` was the wrong gate: it governs the **catalogue** (extras, contract
templates — what a rental *costs*) and is deliberately manager-only, while the Bookings row
of the visibility matrix gives the receptionist and the supervisor "full". A receptionist who
cannot check a car out cannot work the front desk. The accountant keeps `bookings.view` and
audits; the maintenance officer has neither.

## Bugs found while implementing

Three defects that were not in the original audit, all pre-existing:

1. **🔴 `record_payment` could never have worked.** `payments.reference` is `NOT NULL` and
   unique, and the action never set it — every attempt threw a not-null violation. Now
   numbered through `SequenceGenerator` (a document number must not collide, and the service
   is already inside the transaction the generator requires).
2. **🔴 A service-built payment posted backwards.** `Payment` had no `direction` cast, so
   the attribute was a string from the Filament form but a `PaymentDirection` instance when
   a service built it. `PaymentPoster` compared it to the string `'inbound'`, so a
   service-built payment fell through to the refund branch: cash credited, receivable
   debited. Fixed by casting `direction` and comparing against the enum. Masked until now
   because the only caller was the form.
3. **🟡 Payments carried no booking dimension.** Matrix E10–E14 require it, but
   `PaymentPoster` never set `bookingId`, so "what is still owed on booking X" could not be
   asked of the ledger at all — only "what does this customer owe across every rental ever".
   `bookingSettlement()` depends on this.

Two smaller ones: the `record_payment` modal's `financial_account_id` used
`->relationship('financialAccount')`, which resolves against the *Booking* and threw a
`LogicException` the moment the modal opened; and `BookingAvailabilityService::calendarFeed()`
titled every event "N/A" by reading a `full_name` property that does not exist on `Customer`.

A second review pass found four more, all pre-existing and now fixed:

4. **🔴 `bookingSettlement()` counted reversals as payments.** `paid` summed *every* credit
   on the receivable account, and a correcting reversal of E02 also credits 1110 — so a
   reversed booking showed the customer as having paid the invoiced amount twice over.
   `paid` now counts only rows `PaymentPoster` wrote (source_type `payment`);
   `customerStatement()`'s `settled` had the same defect and got the same fix.
5. **🔴 Editing an extras line never recomputed `bookings.extras_total`.** The booking kept
   whatever the wizard typed (and E04 posted) while the lines showed something else. Line
   changes now run `BookingService::syncExtrasTotals()`; the wizard field is disabled so the
   two can never drift apart again.
6. **🟡 `cancel` did not post E09.** The `canDelete()` docblock claimed it reversed E02–E08;
   the method flipped the status and stopped. It now reverses each open booking row through
   `AccountingService::reverse()` inside the transaction — which the docblock always said it
   did.
7. **🟡 An overpayment inflated the receivable.** `PaymentPoster` credited the full amount
   to 1110 whatever the customer owed, fabricating a credit balance the statements could not
   see. It now clears the outstanding receivable (per booking, or per customer for
   unallocated payments) and posts the remainder to 2500 Customer Credit Balances (E19).

A third pass, reviewing the fixes above, found three more — two of them introduced by those
very fixes:

8. **🔴 The E19 split stranded every payment taken before hand-over.** A deposit at booking
   time is the ordinary case: the rental is not invoiced until pickup, so there is no
   receivable and the *whole* payment became a credit balance — posted without a
   `booking_id`, so nothing tied it back. The booking then read "paid 0 / outstanding in
   full" on the same screen as a payments list showing the customer's money, and receivables
   ageing chased a customer who had paid. E19 now carries the booking dimension, and
   `bookingSettlement()`, `customerStatement()` and `receivablesAgeing()` all net account
   2500: `paid` includes it, `owed`/`outstanding` subtract it. Applying a credit through a
   `compensation` payment is a no-op across all three by construction.
9. **🔴 (introduced by fix 8) The split measured the wrong account.** Moving the aggregation
   into `ReportService` picked up `RECEIVABLE_CODES`, which spans 1110 *and* 1120 Fines
   Receivable — while the payment's credit leg only ever lands on 1110. A customer paying
   off a traffic fine would have credited the rental receivable, driving 1110 to −5 000
   while the fine stayed open on 1120. `openReceivableForBooking()`/`…ForCustomer()` now
   measure `CUSTOMER_RECEIVABLE_CODES` (1110 alone); the reporting figures still span both,
   because a customer does owe their fines.
10. **🟡 (introduced by fix 5) The extras guard read a stale parent.** `$line->booking`
    memoises, so a line touched after its booking changed was judged against — and
    recomputed from — the copy loaded earlier. Both the freeze check and the totals
    recompute now read the parent from the database.

Plus three smaller ones: the `pickup_range` filter used `whereDate`, which the Postgres
session evaluates in UTC and dropped any booking picked up before 01:00 Algiers time — it now
uses half-open app-timezone bounds; the operate-permission migration was dated
`2026_08_09` (renamed `2026_08_01_000001`); the extend availability check moved inside the
transaction; `syncExtrasTotals()` stopped using `saveQuietly()`, which had suppressed
`HasAuditColumns` and `LogsActivity` so a money column moved with nobody attached to it; and
the branch filter switched from `pickup_branch_id` to `branch_id`, the column
`getEloquentQuery()` actually pins — filtering one notion of branch while enforcing another
is how a manager ends up trusting a list that does not mean what it says.

## Gaps and risks

- **🔵 `total_amount` is money and ungated on the row.** Deliberate, not an oversight: a
  receptionist must see the price to take payment. The *derived* figures (paid, outstanding)
  are the ones behind `reports.view_financials`. Recorded so nobody "fixes" it later.
- **🔵 `is_blacklisted` is still not enforced** at booking creation — see
  [`09-customer.md`](09-customer.md) gap 1.
- **🔵 Other resources likely share the table-action authorization gap.** Filament's
  `EditAction`/`DeleteAction` do not consult `canEdit()`/`canDelete()`. This resource and
  `ContractTemplateResource` close it; the rest are only safe while their `canAccess()`
  equals their write permission.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/BookingResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/LedgerWiringTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/CloseoutPricingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

The Phase 5 concurrency test is non-negotiable and must still pass: two overlapping
bookings in parallel transactions, exactly one commits.

By hand: run a booking through confirm → checkout → checkin and confirm the ledger gets
revenue at checkout, not at creation. Then count queries on `/admin/bookings` with 25 rows
before and after eager loading. Attempt to edit the car on a checked-out booking and
confirm it is refused.
