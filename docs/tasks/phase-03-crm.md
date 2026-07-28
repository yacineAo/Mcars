# Phase 3 — CRM

**Status: ⬜** · Depends on: Phase 1 · Closes: **REQ-04** (except financial tabs), **ADV-02**

Customers and their identity documents, managed and verifiable.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 2 · [`../07-enums.md`](../07-enums.md) §CRM

## Deliverables

### Tables
- [ ] `customers` — individual/company, national ID (NIN), licence number + expiry + category,
      contact, wilaya, rating, blacklist, source
- [ ] `customer_documents` — type, number, issue/expiry, `verified_at`, `verified_by_id`
- [ ] `additional_drivers` (booking-scoped; the FK to `bookings` lands in Phase 5 — create the table
      here or defer it there, but do not duplicate it)

### Enums
- [ ] `CustomerType`, `CustomerDocumentType`, `CustomerSource` + labels in all three locales

### Resource
- [ ] `CustomerResource` with a tabbed view page: Profile · Documents · Bookings *(stub → Phase 5)* ·
      Financials *(stub → Phase 7)* · Fines *(stub → Phase 6)* · Activity

### Behaviour
- [ ] Document upload with front/back images and expiry tracking, on the **private disk**
- [ ] Verify action recording who verified and when
- [ ] Blacklist flag with reason, surfaced as a prominent warning **wherever the customer is selected**,
      not only on their own page
- [ ] Duplicate detection at creation on `national_id`, `driving_license_number` and phone
- [ ] Rating 1–5 with notes

## Deliberate design point

**Customers are company-wide, not branch-scoped.** A customer registered at one branch must be
serviceable at another — branch-scoping them breaks walk-in business across locations. Add
`registered_branch_id` for attribution only; it must never be used for scoping. Same for `car_owners`.
See [`../08-multi-branch-retrofit.md`](../08-multi-branch-retrofit.md) §D2 exclusions.

Financial figures on the customer page (amounts owed, deposits, fines) stay **stubs** until Phase 7 —
they are ledger queries, and the ledger does not exist until Phase 4. Do not add a
`customers.outstanding_balance` column to fill the gap.

## Tests

- [ ] Duplicate national ID blocked at creation
- [ ] Expired driving licence flagged
- [ ] Blacklisted customer surfaces a warning at selection time
- [ ] Document files unreachable without authorisation
- [ ] A customer created at one branch is visible from another

## Definition of done

Register a customer with ID and licence scans, see expiry warnings, verify the documents. Gates green.
