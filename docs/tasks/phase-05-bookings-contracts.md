# Phase 5 — Bookings, Calendar & Contracts

**Status: ✅ Done** · Depends on: Phases 2, 3, 4 · Closes: **REQ-05**, **REQ-06**, **ADV-01**, **ADV-02**,
**ADV-05** (contract delivery)

Rentals booked without collision; contracts generated, signed and delivered.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 3 ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-002, ADR-005, ADR-010 ·
[`../05-accounting-model.md`](../05-accounting-model.md) rows E01–E08

## Deliverables

### Tables
- [x] `bookings` — status, period, pickup/return branches, **priced snapshot**, handover odometer and
      fuel, cancellation
- [x] `car_blocks` — maintenance / owner use / transfer windows on the same calendar
- [x] `extras` + `booking_extras`, `condition_reports`, `contract_templates`, `contracts`,
      `contract_signatures`, `additional_drivers` (if not created in Phase 3)

### ⚠ Double-booking prevention — the database, not PHP

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- already enabled in Phase 0

ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
  EXCLUDE USING gist (
    car_id WITH =,
    tstzrange(pickup_at, expected_return_at, '[)') WITH &&
  ) WHERE (status IN ('confirmed', 'active', 'overdue'));
```

- [x] Constraint above, plus the equivalent on `car_blocks`, plus a trigger checking the two against
      each other
- [x] `draft` and `pending` deliberately excluded, so several quotes may overlap; only one confirms
- [x] `23P01` translated into a friendly validation error
- [x] **Comment the app-level check as UX-only**, or someone will later "optimise away" the constraint

### Services
- [x] **`BookingAvailabilityService`** — `isAvailable()`, `availableCars()`, `conflictsFor()`,
      `calendarFeed()`, `reserve()`, `extend()`
- [x] **`PricingService`** — duration tiers, extras, discount, tax, deposit → `BookingQuote`,
      **snapshotted onto the booking at confirmation** so later price-list changes cannot rewrite
      history. Also `closeout()` for extra km, fuel, late hours, cleaning.
- [x] **`ContractService`** — generate from template, **freeze `content_snapshot`** (ADR-005), render
      PDF, `amend()`, `close()`
- [x] **`SignatureService`** — OTP issue/verify, drawn signature capture, **SHA-256 of the PDF as it
      stood when each party signed**
- [x] **`MessagingService` v1** — email delivery of the contract PDF, every attempt logged
      to `notification_logs`

### UI
- [x] **`BookingCalendarPage`** — resource timeline, one row per car, drag-to-create, colour by
      status, maintenance blocks rendered inline
- [x] Booking wizard: customer → car (availability-filtered) → dates → extras → quote → confirm
- [x] Checkout / check-in with `condition_reports`: odometer, fuel level, damage diagram, photos,
      customer signature
- [x] `BookingResource`, `ContractResource`, `ContractTemplateResource`, `ExtraResource`,
      `CarBlockResource`, `ConditionReportResource`
- [x] Un-stub the Bookings tab on `CarResource` and `CustomerResource`

### Ledger wiring (uses Phase 4)
- [x] `BookingPoster` posts E02–E08 at **contract activation**, not confirmation (ADR-010), and the
      closeout adjustments at return
- [x] Deposits: **only** the simple take / refund-in-full path here. The full lifecycle
      (deductions, forfeit, overflow to receivable) is Phase 6.

## Tests

- [x] **Concurrency test** — two overlapping bookings for the same car issued in parallel
      transactions; **exactly one commits**, the other gets `23P01`. Non-negotiable.
- [x] A maintenance block prevents booking that car
- [x] Contract PDF regenerates **identically** from `content_snapshot` after the template is edited
- [x] Signature hash matches the delivered PDF
- [x] Rental revenue appears in the ledger on **activation**, not on confirmation
- [x] A confirmed-then-cancelled booking leaves no revenue behind
- [x] Arabic contract renders RTL correctly

## Definition of done

Book a car from the calendar, be refused when double-booking it, check it out, generate and sign the
contract, send it by email, check it back in. Gates green.
