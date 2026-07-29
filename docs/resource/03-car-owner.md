# 03 — CarOwner (Fleet)

**Model:** `App\Models\CarOwner` · **Slug:** `/admin/car-owners` · **Status:** 🔴 needs work

Closes **REQ-03** (third-party cars) together with
[`04-car-ownership-agreement.md`](04-car-ownership-agreement.md) and
`25-owner-installment.md`. See [`../tasks/phase-02-fleet.md`](../tasks/phase-02-fleet.md) and
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-006.

## What it is for

The people and companies whose cars the business rents out and pays rent on. A manager opens
it to add an owner and agree terms; an accountant opens it to answer the only question an
owner ever asks — *what do you owe me?* REQ-03 names that answer precisely: monthly rent
amount, payment due date, payment status, remaining balance, number of instalments.

None of it is on this screen.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 6 columns, `->filters([])` **empty**, no default sort |
| create | ✅ | 17 fields, flat, zero sections |
| view | ❌ | absent — and it is the missing piece; see below |
| edit | ✅ | same flat form; nothing frozen |
| row actions | ✅ | `create_agreement`, Edit — via deprecated `->actions([...])` (`:112`) |
| header / toolbar actions | 🟡 | `CreateAction`; `DeleteBulkAction` in a group (`:161`) |
| relation managers | ❌ | **none** — the panel doc specifies three |
| `canAccess()` | ❌ | absent |

`cars_count` uses `->counts('cars')` (`:106`), a single subquery — correct, and the only
relation column on the table, so the index carries no N+1.

## Should be

### Index

Columns: name as one searchable column (`->searchable(['first_name', 'last_name'])` — as
separate columns, "Ahmed Benali" matches nothing), `company_name` (toggleable, hidden by
default), `phone`, `type`, `cars_count`, active agreements count, `is_active` icon.

Filters, none of which exist:

- `SelectFilter::make('type')` — individual versus company.
- `TernaryFilter::make('is_active')`, defaulting to active.
- **Has an active agreement** — the operationally useful one. An owner with no live
  agreement is a dormant record and should be filterable out.
- `SelectFilter::make('wilaya')`.
- Branch filter, visible only with `branches.view_all`.

`defaultSort('created_at', 'desc')`.

Gated on `reports.view_financials`, add a toggleable **outstanding balance** column sourced
from `ReportService::ownerStatement()` (`ReportService.php:497`) — derived, never stored.
CLAUDE.md bans `owner_installments.amount_paid` for exactly this reason, and the service
already computes the balance as account-2200 credits minus debits.

### Create

Section the 17 fields:

1. **Identity** — type (`live()`; company hides first/last name and shows company name and
   trade register), first name, last name, company name, trade register, NIN.
2. **Contact** — phone, WhatsApp, email, address, wilaya.
3. **Payment details** — bank name, RIB, CCP account, BaridiMob number. Algeria-specific and
   the fields an accountant actually needs; give them their own section and see gap 5.
4. **Status** — notes, `is_active`.

`user_id` must never be settable. It is kept but unused (CLAUDE.md, ADR-007) and is correctly
absent from the form — keep it out.

### View

**Add one.** This is the strongest case for a view page in the Fleet cluster: an owner is a
record with four related tables hanging off it and a derived balance that already exists in
`ReportService` and is currently reachable only by running a report. Sections:

1. **Identity and contact.**
2. **Payment details**, gated on `reports.view_financials`.
3. **Statement** — total due, total paid, balance, every figure from
   `ReportService::ownerStatement()`, memoised once per request the way
   `ViewCar::profitability()` does it (`ViewCar.php:118`). Gated on
   `reports.view_financials`. Never sum anything locally.
4. The four relation tables below.

That page answers REQ-03 in one screen. Today the same question needs a report run from
`ReportResource`.

### Edit

Everything stays editable — an owner's phone, bank and address genuinely change. `type` should
freeze once an agreement exists, because switching an owner from company to individual after
signing changes what a printed agreement claims. Payment details should be editable but
audited: `LogsActivity` is already on the model, so confirm the RIB and CCP columns appear in
`activity_log`.

### Relations

`CarOwner hasMany`: `cars`, `agreements`, `ownerInstallments`, `payments`
(`CarOwner.php:56-74`). Zero are wired, and
[`../02-filament-panels.md`](../02-filament-panels.md)`:24` explicitly specifies
"`CarOwnerResource` + relation managers: agreements, cars, instalments". That is a
documented deliverable, not a proposal.

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `cars` | **view** | yes | — | plate, brand/model, status, daily rate |
| `agreements` | **edit** | no — terms are maintained here | — | car, model, status, start/end, monthly rent |
| `ownerInstallments` | **view** | yes | `reports.view_financials` | period, due date, amount due, amount paid *(derived)*, status |
| `payments` | **view** | yes | `reports.view_financials` | date, method, amount, reference |

`cars` is read-only here on purpose: a car is created and edited on `CarResource`, and what
makes it this owner's car is an agreement, not a row edited in a sub-table. The two money
tables need their own `canAccess()` on `reports.view_financials` — gating the Statement
section while leaving an instalments table open beside it defeats the gate, the same mistake
called out in [`09-customer.md`](09-customer.md).

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `create_agreement` | row | `is_active` | fleet write | **a service owning agreement creation** | today it writes the row inline; gap 1 |
| View / Edit | row | always | fleet read / write | — | View page to be added |
| **Owner statement** | row or view header | always | `reports.view_financials` | `ReportService::ownerStatement()` via `ReportResource` | Proposal — a direct link to the statement this owner needs, pre-scoped |
| Create | header | always | fleet write | — | keep |
| ~~Delete (bulk)~~ | — | — | — | — | gap 4 |

## Gaps and risks

1. **🔴 `create_agreement` builds an ownership agreement in the resource, incompletely, and
   can 500.** `CarOwnerResource.php:140-157` calls `CarOwnershipAgreement::create()` directly.
   Three separate problems:
   - It writes `'status' => 'active'` as a raw string and defaults
     `monthly_rent_amount` and `share_percentage` to `0` regardless of the `model` chosen, so
     a `fixed_monthly` agreement can be saved with 0 rent and a `revenue_share` one with 0%.
     Nothing validates that the model and the amounts agree.
   - It never sets `payment_day_of_month`, `installments_count` or `first_due_date`. Those are
     the three fields REQ-03 asks for ("payment due date… number of instalments") and the
     three an instalment generator needs. An agreement created here produces no schedule.
   - The database has an `EXCLUDE` constraint `agreements_no_overlap` on
     `(car_id, daterange(start_date, end_date)) WHERE status = 'active'`
     (`2026_07_28_160000_create_fleet_tables.php:248-255`, ADR-006). Creating a second active
     agreement for a car raises a raw `QueryException` with no handling in the action, so the
     user gets a 500 rather than "this car already has an active agreement".
     `FleetManagementTest` asserts the constraint fires; nothing asserts the UI survives it.

   All of it belongs in a service. `app/Actions/` does not exist (README finding 5), so
   `app/Services/`.
2. **🔴 Zero relation managers, against an explicit spec.** See Relations. The consequence is
   concrete: there is no screen in the panel that shows one owner's cars, agreements and
   instalments together, so "what do we owe Ahmed?" is answered by running a report and
   reading a PDF.
3. **🟡 The `car_id` Select in `create_agreement` is wrong either way.**
   `CarOwnerResource.php:119-121` is `Select::make('car_id')->relationship('cars', 'registration_number')`
   — a `hasMany` used as a Select source inside an action form. **Unverified which way it
   resolves**, and both are defects: if it resolves against the record it offers only cars
   whose `car_owner_id` is already this owner, which is chicken-and-egg since the agreement
   is what makes a car third-party; if it resolves against `Car` globally it offers every
   company-owned car in the fleet. Replace it with an explicit `->options()` listing cars that
   have no active agreement. Verify the current behaviour before changing it.
4. **🟡 `DeleteBulkAction` on owners.** An owner is referenced by cars, agreements,
   instalments and ledger rows carrying `car_owner_id`. `car_ownership_agreements.car_owner_id`
   is `cascadeOnDelete` (`2026_07_28_160000_create_fleet_tables.php:140`), so a future
   force-delete removes the agreement history behind the payments. Soft deletes cover the
   normal case, but bulk-deleting the people you owe money to should not be one click away.
5. **🟡 Bank RIB, CCP account and BaridiMob number are readable by every staff role.** They sit
   on an ungated create/edit form. These are payment credentials; the same reasoning that puts
   `reports.view_financials` in front of receivables applies. Move them into a gated section.
6. **🟡 Empty `->filters([])`, no default sort.**
7. **🟡 No `canAccess()`.** Fleet is `read` for receptionist and supervisor per
   [`../02-filament-panels.md`](../02-filament-panels.md) §Role → visibility matrix. Same
   blocker as the rest of the cluster: the live database holds four permissions and no Shield
   per-resource permissions, so a fleet permission pair must be seeded first (README
   finding 2).
8. **🟡 Deprecated `->actions([...])`** — README finding 3.
9. **🔵 `national_id` has no unique constraint.** `car_owners.national_id` is a nullable
   `string` with no index (`2026_07_28_160000_create_fleet_tables.php:46`). Customers got
   `2026_07_28_171000_add_customer_unique_constraints.php`; owners did not, so the same person
   can be entered twice and their instalments split across two records. Worth confirming
   against REQ-03 whether that was deliberate.
10. **🔵 Action labels do not translate.** "New Agreement" is set with `->label()` and never
    `->translateLabel()`. Filament's `HasLabel::getLabel()` only calls `__()` when
    `$shouldTranslateLabel` is true (default `false`), and `AdminPanelProvider` configures
    `Field`, `Column`, `Entry`, `Section`, `Table` and `Schema` — **not `Action`**
    (`AdminPanelProvider.php:65-83`). The French and Arabic strings already exist in
    `lang/fr.json` / `lang/ar.json` ("New Agreement" → "Nouveau contrat"), so the dictionary
    entries are dead weight. This affects every custom action in the Fleet cluster; the fix is
    one `Action::configureUsing(fn (Action $a) => $a->translateLabel())` in the panel
    provider, not ten per-file edits.

## Checklist

- [ ] Move agreement creation into a service that validates model-vs-amounts, requires the
      instalment fields, and turns the `EXCLUDE` violation into a validation message
- [ ] Add a view page: identity, gated payment details, gated statement from
      `ReportService::ownerStatement()`
- [ ] Add the four relation managers — `cars` and `payments` and `ownerInstallments` read-only
      on view, `agreements` editable on edit — with the two money tables gated
- [ ] Replace the `car_id` Select with explicit options: cars without an active agreement
- [ ] Section the form; make `type` `live()`; move bank details into a gated section
- [ ] Add the type / active / has-active-agreement / wilaya filters and a default sort
- [ ] Collapse the two name columns into one searchable column
- [ ] Add a gated, toggleable outstanding-balance column from `ReportService`
- [ ] Reconsider `DeleteBulkAction`
- [ ] `->actions(` → `->recordActions(`
- [ ] Add `canAccess()` once a fleet permission exists
- [ ] Freeze `type` once an agreement exists
- [ ] Decide whether `national_id` should be unique

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FleetManagementTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase9Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/CarOwnerResource.php
```

`Phase9Test` covers `OwnerStatementExport`, which shares `ReportService::ownerStatement()`
with the proposed view page — the figures on the screen and in the exported statement must
come from the same call and must agree.

By hand: create an owner, then use `create_agreement` twice on the same car with overlapping
dates. Today the second attempt raises a database exception in the user's face; it must become
a validation message. Then open the owner as an accountant and confirm the statement and both
money tables appear, and as a receptionist and confirm all three are gone.
