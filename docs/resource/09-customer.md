# 09 — Customer (CRM)

**Model:** `App\Models\Customer` · **Slug:** `/admin/customers` · **Status:** 🟢 done

Closes **REQ-04** (customer records, activity and balances). See
[`../tasks/phase-03-crm.md`](../tasks/phase-03-crm.md).

## What it is for

The office's record of who rents from them. A receptionist opens it to find a returning
customer by phone or NIN and check their licence is valid before handing over keys; a
manager opens it to see what a customer owes. It is the only screen where a customer's
whole history should be answerable without running a report.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 8 columns; `->filters([])` is **empty**; no default sort |
| create | ✅ | flat form, **zero sections** — 25+ fields in one column |
| view | ✅ | 5 infolist sections; Financials correctly gated and correctly sourced |
| edit | ✅ | same flat form as create; nothing frozen |
| row actions | ✅ | `toggle_blacklist`, View, Edit — via deprecated `->actions([...])` |
| header / toolbar actions | ✅ | `CreateAction` on index; `DeleteBulkAction` in a bulk group |
| relation managers | 🟡 | 1 of 7 possible: `DocumentsRelationManager` only |
| `canAccess()` | ❌ | absent — any staff role reaches it |

`ViewCustomer` is the one part in good shape: the Financials section is gated on
`reports.view_financials` (`ViewCustomer.php:78`) and every figure comes from
`ReportService::customerStatement()`, memoised with `??=` so five entries cost one query
(`ViewCustomer.php:109`). Deposits are shown separately from the balance, which is
correct — a deposit is a liability, never revenue.

## Should be

### Index

Columns, in order: `code` (sortable, searchable), full name (searchable across both name
columns), `phone`, `national_id` as **NIN**, `company_name` (toggleable, hidden by
default — most customers are individuals), blacklist icon, `is_active` icon.

Add the filters the table has none of:

- `TernaryFilter::make('is_blacklisted')` — the single most operationally useful filter here.
- `TernaryFilter::make('is_active')`, defaulting to active only.
- `SelectFilter::make('source')` from `CustomerSource`.
- `SelectFilter::make('wilaya')` — Algeria has 58; a manager segments by region.

Default sort `created_at desc`. Searching `first_name` and `last_name` as separate
columns means "Ahmed Benali" matches nothing — collapse them into one searchable column
with `->searchable(['first_name', 'last_name'])`.

A receptionist needs code, name, phone, licence expiry and the blacklist flag. A manager
additionally wants outstanding balance — but that is a derived figure, so it belongs as
an optional, `reports.view_financials`-gated column sourced from `ReportService`, never a
stored column.

### Create

Section the form. It is currently 25+ fields in a flat list, which is why the screen
feels wrong:

1. **Identity** — code (derived, read-only), first/last name, company name, gender, date
   and place of birth, nationality, NIN.
2. **Driving licence** — number, category, issue date, expiry, issuing authority.
3. **Contact** — phone, secondary phone, WhatsApp, email, address, city, wilaya, country.
4. **Commercial** — source, rating, notes.
5. **Status** — `is_active` only.

`is_blacklisted` and `blacklist_reason` must **come out of the form entirely** (see
Actions). Licence expiry should warn when the date is in the past — a receptionist
checking out a car needs to see that at a glance, not discover it in a contract.

### View

Keep it, and make it the primary screen for this resource — it is the only place the
customer's history comes together. Existing sections are right; add the related tables
below.

### Edit

`code` must be frozen once assigned (it is a generated document number). Everything else
stays editable — customer details genuinely change. `is_blacklisted` must not be
editable here; it is an action with a reason and an audit trail.

### Relations

Customer `hasMany`: `documents`, `bookings`, `payments`, `deposits`, `fines`,
`paymentSchedules`, `contracts`. One of the seven is wired.

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `documents` | edit | no — the office maintains these | — | already built; keep |
| `bookings` | **view** | yes | — | reference, car, pickup/return, status, total |
| `contracts` | **view** | yes | — | number, status, signed at |
| `payments` | **view** | yes | `reports.view_financials` | date, method, amount, reference |
| `deposits` | **view** | yes | `reports.view_financials` | booking, amount, status, held/released at |
| `fines` | **view** | yes | — | notice number, violation date, amount, liability, status |
| `paymentSchedules` | **view** | yes | `reports.view_financials` | first due date, instalments, status |

Read-only means no create, no edit, no delete, no bulk actions — these are history, and
the write path for each lives on its own resource. The four money tables each need their
own `canAccess()` on `reports.view_financials`: gating the Financials *summary* while
leaving a payments table open beside it defeats the gate.

Seven relation managers is too many tabs to scan. Group them: **Rentals** (bookings +
contracts), **Money** (payments + deposits + schedules), **Fines**, **Documents**.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `toggle_blacklist` | row | always | **nothing** | raw `update()` — should be a service | sets 3 columns inline; gap 3 |
| `ViewAction` | row | always | — | — | keep |
| `EditAction` | row | always | **nothing** | — | keep, once gated |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 4 |

## Gaps and risks

1. **🔴 Blacklisting does nothing.** `is_blacklisted` is written by this resource and
   **never read anywhere in the application** — verified by grepping all of `app/` outside
   the model and this resource. A blacklisted customer can still be quoted, booked and
   handed a car. Either the flag gains an enforcement point in `BookingService` /
   `BookingAvailabilityService`, or the UI must stop implying protection it does not give.
   This is the most serious finding on the screen.
2. **🔴 No `canAccess()`.** Any staff role — including a maintenance officer — can open,
   edit and bulk-delete customer records, which carry NIN, date of birth and address.
   Reading should be broad (a receptionist needs it); creating, editing and deleting
   should not be. Needs a policy or an explicit `canAccess()`.
3. **🔴 The blacklist action holds business rules.** `CustomerResource.php:163-175` calls
   `$record->update()` directly, setting three columns and inventing the audit semantics
   inline. Per ADR-013 a Filament action defines UI and delegates; this should call a
   service that owns the transition, records who did it, and can be extended to check
   whether the customer has an active booking before blacklisting them.
4. **🟡 `DeleteBulkAction` on customers.** Customers are referenced by bookings,
   contracts and ledger rows. Soft deletes protect the data, but a bulk delete of
   customers is not an operation a business wants one click away. Restrict to
   super_admin, or drop it and keep single-record delete with a confirmation.
5. **🟡 Empty `->filters([])`** on a table that grows without bound.
6. **🟡 Deprecated `->actions([...])`** — should be `->recordActions([...])`.
7. **🟡 No licence-expiry surfacing.** The data is there; nothing warns on it. Document
   expiry alerts exist for cars (Phase 8) but not for customer licences — worth
   confirming against REQ-04 whether that was intended.
8. Note: `app/Actions/` **does not exist** in this codebase, so the Action layer named in
   CLAUDE.md's layering table has no home yet. Point 3 should land in
   `app/Services/CustomerService.php` unless the Action layer is introduced first.

## Checklist

- [x] Add `canAccess()` and decide the read/write permission split for CRM
- [x] Move blacklist transitions into a service; keep the Filament action as UI only
- [ ] Establish whether `is_blacklisted` should block booking, and wire it (or file a
      decision that it is advisory only, and relabel the UI to match)
- [x] Remove `is_blacklisted` / `blacklist_reason` from the create and edit forms
- [x] Section the form into Identity / Licence / Contact / Commercial / Status
- [x] Collapse the two name columns into one searchable column
- [x] Add the four filters; set `defaultSort('created_at', 'desc')`
- [x] `->actions(` → `->recordActions(`
- [x] Add the six read-only relation managers, grouped, with money ones gated
- [x] Freeze `code` on edit
- [x] Reconsider `DeleteBulkAction`
- [x] Surface an expired driving licence on the index and the view page

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CustomerManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CustomerResource
```

By hand: open a customer as an accountant (has `reports.view_financials`) and confirm the
Financials section and all money tables appear; repeat as a receptionist and confirm all
of them are gone, not just the summary. Blacklist a customer with a reason, then attempt
to create a booking for them and record what actually happens.
