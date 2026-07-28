# Phase 2 — Fleet Management

**Status: ⬜** · Depends on: Phase 1 · Closes: **REQ-02** (except profitability tab), **REQ-03**
(schema), **REQ-12**, **REQ-13** (data), **ADV-02**

The full fleet in the system with owners, agreements, documents and maintenance.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 1 ·
[`../07-enums.md`](../07-enums.md) §Fleet · [`../06-design-decisions.md`](../06-design-decisions.md) ADR-006, ADR-009

## Deliverables

### Tables
- [ ] `car_categories`, `vendors`
- [ ] `car_owners`
- [ ] **`car_ownership_agreements`** — `model` (`fixed_monthly | revenue_share | hybrid`),
      `monthly_rent_amount`, `share_percentage`, `start_date`, `end_date`, `payment_day_of_month`,
      `installments_count`, `status`. **Not columns on `cars`** (ADR-006): rent terms change over
      time and storing them on the car rewrites last year's instalments.
- [ ] `cars` — identity, state, pricing, acquisition, telematics, plus the
      `*_expiry_date` mirror columns (a derived, rebuildable cache of `car_documents`)
- [ ] `car_documents`, `maintenance_logs`, `maintenance_schedules`

### Constraints
- [ ] `EXCLUDE` constraint preventing overlapping **active** ownership agreements per car
- [ ] Unique on `cars.chassis_number` and `cars.registration_number`

### Enums
- [ ] `CarStatus`, `OwnershipType`, `FuelType` (include **`gpl`** — common in Algeria and distinct
      from petrol), `TransmissionType`, `BodyType`, `CarDocumentType`, `MaintenanceType`,
      `MaintenanceStatus`, `AgreementModel`, `AgreementStatus`, `VendorType`
- [ ] Labels in `lang/{ar,fr,en}/enums.php`

### Resources
- [ ] **`CarResource`** with a tabbed view page: Overview · Documents · Maintenance ·
      Bookings *(stub → Phase 5)* · **Profitability** *(stub → Phase 7)* · Owner · Activity
- [ ] `CarOwnerResource` (relation managers: agreements, cars), `CarOwnershipAgreementResource`,
      `CarDocumentResource` (global list filtered by expiry — the renewals worklist),
      `MaintenanceLogResource`, `MaintenanceScheduleResource`, `VendorResource`, `CarCategoryResource`

### Services
- [ ] **`FleetStatusService`** — status state machine. A car with an active booking cannot become
      `sold`; a car in `maintenance` cannot be picked up.
- [ ] **`MaintenanceSchedulerService`** — next-due from `interval_km` / `interval_days`, whichever
      arrives first
- [ ] Observer keeping `cars.*_expiry_date` in sync with `car_documents`

### Media
- [ ] Car `gallery` and `damage` collections; document scans on a **private disk** (ADR-009). No
      `car_photos` table.

## Explicitly deferred to Phase 4

Maintenance costs and document-renewal costs are recorded on their rows but **not posted to the
ledger** — `AccountingService` does not exist yet. Mark every such spot with `// PHASE-4:` so the
retro-wiring step in Phase 4 can find them.

## Tests

- [ ] Illegal status transitions rejected
- [ ] Next-service-due recomputes when a maintenance log completes
- [ ] Overlapping active agreements rejected **by the database**
- [ ] Document expiry mirror stays correct after edits and deletes
- [ ] Document files are not reachable without authorisation

## Definition of done

Add a third-party car with an owner, a fixed-monthly agreement, an insurance policy and a service
history. Gates green.
