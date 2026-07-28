# Phase 2 — Fleet Management

**Status: ✅** (commit `ed843cc`) · Depends on: Phase 1 · Closes: **REQ-02** (except profitability tab), **REQ-03**
(schema), **REQ-12**, **REQ-13** (data), **ADV-02**

The full fleet in the system with owners, agreements, documents and maintenance.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 1 ·
[`../07-enums.md`](../07-enums.md) §Fleet · [`../06-design-decisions.md`](../06-design-decisions.md) ADR-006, ADR-009

## Deliverables

### Tables
- [x] `car_categories`, `vendors`
- [x] `car_owners`
- [x] **`car_ownership_agreements`** — `model` (`fixed_monthly | revenue_share | hybrid`),
      `monthly_rent_amount`, `share_percentage`, `start_date`, `end_date`, `payment_day_of_month`,
      `installments_count`, `status`. **Not columns on `cars`** (ADR-006): rent terms change over
      time and storing them on the car rewrites last year's instalments.
- [x] `cars` — identity, state, pricing, acquisition, telematics, plus the
      `*_expiry_date` mirror columns (a derived, rebuildable cache of `car_documents`)
- [x] `car_documents`, `maintenance_logs`, `maintenance_schedules`

### Constraints
- [x] `EXCLUDE` constraint preventing overlapping **active** ownership agreements per car
- [x] Unique on `cars.chassis_number` and `cars.registration_number`

### Enums
- [x] `CarStatus`, `OwnershipType`, `FuelType` (include **`gpl`** — common in Algeria and distinct
      from petrol), `TransmissionType`, `BodyType`, `CarDocumentType`, `MaintenanceType`,
      `MaintenanceStatus`, `AgreementModel`, `AgreementStatus`, `VendorType`
- [x] Labels in `lang/{ar,fr,en}/enums.php`

### Resources
- [x] **`CarResource`** with a tabbed view page: Overview · Documents · Maintenance ·
      Bookings *(stub → Phase 5)* · **Profitability** *(stub → Phase 7)* · Owner · Activity
- [x] `CarOwnerResource` (relation managers: agreements, cars), `CarOwnershipAgreementResource`,
      `CarDocumentResource` (global list filtered by expiry — the renewals worklist),
      `MaintenanceLogResource`, `MaintenanceScheduleResource`, `VendorResource`, `CarCategoryResource`

### Services
- [x] **`FleetStatusService`** — status state machine. A car with an active booking cannot become
      `sold`; a car in `maintenance` cannot be picked up.
- [x] **`MaintenanceSchedulerService`** — next-due from `interval_km` / `interval_days`, whichever
      arrives first
- [x] Observer keeping `cars.*_expiry_date` in sync with `car_documents`

### Media
- [x] Car `gallery` and `damage` collections; document scans on a **private disk** (ADR-009). No
      `car_photos` table.

## Explicitly deferred to Phase 4

Maintenance costs and document-renewal costs are recorded on their rows but **not posted to the
ledger** — `AccountingService` does not exist yet. Mark every such spot with `// PHASE-4:` so the
retro-wiring step in Phase 4 can find them.

## Tests

- [x] Illegal status transitions rejected
- [x] Next-service-due recomputes when a maintenance log completes
- [x] Overlapping active agreements rejected **by the database**
- [x] Document expiry mirror stays correct after edits and deletes
- [x] Document files are not reachable without authorisation

## Definition of done

Add a third-party car with an owner, a fixed-monthly agreement, an insurance policy and a service
history. Gates green.
