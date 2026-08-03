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

This table described the resource before an earlier round of fixes and was left unupdated; it has
now been checked against the running code and corrected (see Gaps and risks).

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 8 columns, `full_name` collapsed and searchable across both fields, 4 filters, `defaultSort('created_at', 'desc')` |
| create | ✅ | sectioned form — Identity / Driving Licence / Contact / Commercial / Status; `is_blacklisted` off the form entirely |
| view | ✅ | 5 infolist sections; Financials correctly gated and correctly sourced |
| edit | ✅ | same sectioned form as create; `code` disabled + not dehydrated |
| row actions | ✅ | `toggle_blacklist` (delegates to `CustomerService`), `view_history`, View, Edit — via `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` on index; `->bulkActions([])` |
| relation managers | ✅ | 7 of 7: documents, bookings, contracts, payments, deposits, paymentSchedules, fines |
| `canAccess()` | ✅ | gates on `fleet.view`; writes on `fleet.manage` |

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

1. ~~**🔴 Blacklisting does nothing.**~~ → **Resolved.** **Decided: it blocks booking.**
   `App\Rules\CustomerNotBlacklisted` is attached to `BookingResource`'s shared `customer_id`
   field (used by both create and edit), so a blacklisted customer cannot be picked for a new
   booking and cannot be assigned to an unstarted draft either. Refuses with a field error
   (`This customer is blacklisted and cannot be booked.`), not a silent no-op or a raw
   exception.

   `disabled()` freezing `customer_id` once the rental starts is **not** what stops the rule
   firing after that point — a disabled field is still validated against its loaded value even
   though it is not submitted (Filament's `isValidatedWhenNotDehydrated` defaults `true`). Without
   a guard, a customer blacklisted *after* pickup would permanently fail validation on every
   future edit to that booking, on a field nobody is touching. The rule takes `$alreadyStarted`,
   derived from the record loaded when the form was built (not from submitted data, so it cannot
   be spoofed), and no-ops once true — that flag is load-bearing; do not drop it while
   simplifying this attachment later. See `BookingResourceTest.php`.
2. ~~**🔴 No `canAccess()`.**~~ → **Resolved.** Gates on `fleet.view`; `canCreate()`/`canEdit()`
   on `fleet.manage`; `canDelete()` hardcoded `false`.
3. ~~**🔴 The blacklist action holds business rules.**~~ → **Resolved.** The `toggle_blacklist`
   row action delegates to `CustomerService::toggleBlacklist()`.
4. ~~**🟡 `DeleteBulkAction` on customers.**~~ → **Resolved.** `->bulkActions([])`.
5. ~~**🟡 Empty `->filters([])`.**~~ → **Resolved.** `is_blacklisted`, `is_active` (default
   true), `source`, `wilaya`.
6. ~~**🟡 Deprecated `->actions([...])`.**~~ → **Resolved.** Uses `->recordActions([...])`.
7. ~~**🟡 No licence-expiry surfacing.**~~ → **Resolved.** The `license_expiry_date` column
   carries a coloured icon (danger + warning triangle once past, success + check otherwise).
8. Note: `app/Actions/` still does not exist in this codebase (README finding 5) — moot for
   this resource now that point 3 landed in `CustomerService`, but still true generally.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/CustomerManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/BookingResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CustomerResource app/Rules/CustomerNotBlacklisted.php
```

By hand: open a customer as an accountant (has `reports.view_financials`) and confirm the
Financials section and all money tables appear; repeat as a receptionist and confirm all
of them are gone, not just the summary. Blacklist a customer with a reason, then try to
create a booking for them — the customer field must refuse with a validation message, not
let the booking through.
