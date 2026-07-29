# 18 — Booking (Bookings)

**Model:** `App\Models\Booking` · **Slug:** `/admin/bookings` · **Status:** 🟡 partial

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
| index | ✅ | 7 columns; one filter (status); `defaultSort('created_at','desc')` |
| create | ✅ | a `Wizard` — Customer & Car, then dates/pricing, then Additional Drivers repeater |
| view | ❌ | **absent** — the hub record has no hub screen |
| edit | ✅ | same wizard; nothing frozen after revenue is posted |
| row actions | ✅ | confirm, checkout, checkin, cancel, record_payment, Edit, **Delete** |
| header / toolbar actions | 🟡 | `CreateAction` only; no bulk actions |
| relation managers | ❌ | `getRelations()` returns `[]` |
| `canAccess()` | ❌ | absent |

**The lifecycle actions are the best-built part of the panel and should be left alone.**
`confirm`, `checkout`, `checkin` and `cancel` each delegate to `BookingService`
(`BookingResource.php:151-257`), with a comment recording why: they used to be bare status
updates, so a car could be handed over without the rental ever reaching the ledger. The
`visible()` closures compare cast enums properly (`$record->status->is(...)`), which is
the bug ContractResource still has.

## Should be

### Index

This is a working queue, not an archive, and it is missing the filters that make it one.
Add:

- **Returns due today** and **Pickups today** — the two questions a receptionist asks all
  day. The dashboard has widgets for both (`DueReturnsTodayTable`,
  `UpcomingPickupsTable`); the list they link to should be able to reproduce them.
- **Overdue** — `status = Active AND expected_return_at < now()`.
- A date-range filter on `pickup_at`.
- `SelectFilter` on car and on pickup branch (branch only for `branches.view_all`).

Columns: keep `reference`, plate, customer, pickup, expected return, status badge, total.
Two fixes — `customer.first_name` labelled "Customer" shows only a first name, so searching
or scanning for "Benali" fails; render the full name. And add a visual marker for overdue
rows, which currently look identical to active ones.

### Create

The wizard is the right shape. Two things to verify rather than change blind: that the car
select excludes cars already booked for the chosen window (availability is decided by the
Postgres `EXCLUDE` constraint plus `BookingAvailabilityService` per ADR-002 — the resource
must never decide it itself), and that the quote comes from `PricingService`.

`is_blacklisted` on the customer is currently **not** checked anywhere — see
[`09-customer.md`](09-customer.md) gap 1. If blacklisting is to mean anything, this form is
where it bites.

### View

**Add one.** This is the single highest-value change in the panel. A booking is the record
staff refer to when a customer phones, and today answering "what was signed, what was
charged, what came back damaged, what is still owed" means visiting four other screens.

Sections: identity (reference, status, branch), customer and car, the dates including
actual pickup/return against expected, and the money breakdown (subtotal, extras,
discount, total). The money figures are already on the row; anything derived — paid to
date, outstanding — comes from `ReportService`, never a stored column.

### Edit

Freeze after the rental starts. Once `checkout` has posted revenue to the ledger,
`car_id`, `customer_id`, `pickup_at` and the pricing fields must not be editable — the
ledger rows referencing them are append-only, so editing the booking silently desynchronises
the two. Editing should narrow to notes, additional drivers and the expected return date
(an extension), and an extension should be an action that reprices, not a field edit.

### Relations

`Booking` has: `contract` (hasOne), `extras`, `conditionReports`, `additionalDrivers`,
`deposits`, `fines`.

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `contract` | **view** | yes | — | number, status, signed at, link |
| `conditionReports` | **view** | yes | — | direction (in/out), odometer, fuel, damages, photos |
| `extras` | **edit** | no — the office adjusts these | — | extra, quantity, unit price, total |
| `additionalDrivers` | **edit** | no | — | already in the create wizard as a repeater |
| `deposits` | **view** | yes | `reports.view_financials` | amount, method, status, held/released |
| `fines` | **view** | yes | — | notice number, violation date, amount, liability |
| payments | **view** | yes | `reports.view_financials` | date, method, amount — via the `payable` morph |

Payments reach a booking through a polymorphic `payable`, not a `hasMany`, so that one
needs a relation added to the model or a query-based table.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `confirm` | row | `Draft` \| `Pending` | **nothing** | `BookingService::confirm()` | correct |
| `checkout` | row | `Confirmed` | **nothing** | `BookingService::checkOut()` | correct — posts revenue |
| `checkin` | row | `Active` \| `Overdue` | **nothing** | `BookingService::checkInWithCharges()` | correct — posts closeout |
| `cancel` | row | `! status->isTerminal()` | **nothing** | `BookingService::cancel()` | correct — takes a reason |
| `record_payment` | row | not `Cancelled`/`Draft` | **nothing** | `PaymentService::recordPayment()` | **builds the `Payment` in the closure** — gap 1 |
| `EditAction` | row | always | **nothing** | — | must freeze after checkout |
| `DeleteAction` | row | always | **nothing** | — | **restrict to `Draft`** — gap 2 |

## Gaps and risks

1. **🔴 `record_payment` builds a domain object in the resource.**
   `BookingResource.php:278-291` assembles a `Payment` — branch, direction, payable type,
   currency, `received_by_id` — inside a Filament closure, then hands it to
   `PaymentService::recordPayment()`. Every sibling action passes raw form data to the
   service and lets it construct. This one encodes the payment's shape in the UI layer, so
   a second caller (the payments screen, an import, a test) can construct it differently.
   Move the construction into `PaymentService`.
2. **🔴 `DeleteAction` on a booking that has posted to the ledger.** A completed booking
   has revenue, a receivable and possibly a deposit behind it. Soft delete keeps the row,
   but hiding the booking while its ledger entries stand leaves figures that reconcile to
   nothing. Restrict deletion to `Draft`, or drop the action and use `cancel`.
3. **🔴 No `canAccess()`.** Every staff role can create, edit and delete bookings.
4. **🟡 N+1 on the index.** `car.registration_number` and `customer.first_name` resolve a
   relation per row with no eager loading anywhere in the resource or `ListBookings`.
   At 25 rows that is 50 extra queries on the most-visited screen in the app.
5. **🟡 Nothing frozen on edit** — see Edit above.
6. **🟡 One filter on a queue table** — see Index above.
7. **🟡 Deprecated `->actions([...])`.**
8. **🔵 `total_amount` is money and ungated.** A judgement call rather than a defect: a
   receptionist must see the price to take payment, so it should stay visible. Worth
   recording the decision so nobody "fixes" it later by hiding it.

## Checklist

- [ ] Add a view page with the sections above
- [ ] Add the 7 relation managers, split view/edit as tabled, money ones gated
- [ ] Move `Payment` construction from the action into `PaymentService`
- [ ] Restrict or remove `DeleteAction`; prefer `cancel`
- [ ] Add `canAccess()` and decide the create/edit/delete permission split
- [ ] Eager-load `car` and `customer` on the index
- [ ] Add the due-today / pickups-today / overdue filters and a `pickup_at` range
- [ ] Show the customer's full name; mark overdue rows visually
- [ ] Freeze `car_id`, `customer_id`, `pickup_at` and pricing once checked out
- [ ] Make "extend a rental" an action that reprices, not a date edit
- [ ] `->actions(` → `->recordActions(`
- [ ] Confirm the car select respects `BookingAvailabilityService`

## Verification

```bash
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
