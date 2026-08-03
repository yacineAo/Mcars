# 03 — CarOwner (Fleet)

**Model:** `App\Models\CarOwner` · **Slug:** `/admin/car-owners` · **Status:** 🟢 complete

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
| index | ✅ | `full_name` column, 7 columns including `active_agreements_count` and gated toggleable `outstanding_balance`; filters: type, is_active, has-active-agreement, wilaya; `defaultSort('created_at', 'desc')` |
| create | ✅ | 4 sections (Identity, Contact, Payment Details gated, Status); `type` is `live()`; `first_name`/`last_name` vs `company_name`/`trade_register` toggle on type |
| view | ✅ | Identity & Contact, gated Payment Details, gated Statement, Notes + 3 relation groups (Cars, Instalments, Payments) |
| edit | ✅ | same sections; `type` freezes when agreements exist; agreements relation manager on edit page |
| row actions | ✅ | `ViewAction`, `create_agreement`, `EditAction`, `DeleteAction` hidden when cars or agreements exist — all via `->recordActions([...])` |
| header / toolbar actions | ✅ | only `CreateAction`; `DeleteBulkAction` removed |
| relation managers | ✅ | `CarsRelationManager` (view, read-only), `AgreementsRelationManager` (edit, writable), `InstallmentsRelationManager` (view, gated), `PaymentsRelationManager` (view, gated) |
| `canAccess()` | ✅ | `fleet.view` / `fleet.manage` |

`cars_count` uses `->counts('cars')`, a single subquery — no N+1.

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

1. ~~**🔴 `create_agreement` builds an ownership agreement in the resource, incompletely...**~~ →
   **Resolved.** Delegated to `OwnerAgreementService` which validates model-vs-amounts (requires
   `monthly_rent_amount` for `fixed_monthly` / `hybrid`, `share_percentage` for `revenue_share` /
   `hybrid`), includes instalment fields (`payment_day_of_month`, `installments_count`,
   `first_due_date`), and proactively checks for overlapping active agreements before creating —
   turns the `EXCLUDE` violation into a validation message.
2. ~~**🔴 Zero relation managers, against an explicit spec.**~~ → **Resolved.** Four relation
   managers wired: `CarsRelationManager` (view, read-only), `AgreementsRelationManager` (edit,
   writable), `InstallmentsRelationManager` (view, gated on `reports.view_financials`),
   `PaymentsRelationManager` (view, gated on `reports.view_financials`).
3. ~~**🟡 The `car_id` Select in `create_agreement` is wrong either way.**~~ → **Resolved.**
   Replaced with explicit `->options()` listing cars without an active agreement (via
   `whereDoesntHave('agreements', fn ($q) => $q->where('status', 'active'))`).
4. ~~**🟡 `DeleteBulkAction` on owners.**~~ → **Resolved.** `DeleteBulkAction` removed. Single row
   `DeleteAction` hidden when `cars_count > 0 || agreements()->exists()`.
5. ~~**🟡 Bank RIB, CCP account and BaridiMob number are readable by every staff role.**~~ →
   **Resolved.** Payment Details section gated on `reports.view_financials`.
6. ~~**🟡 Empty `->filters([])`, no default sort.**~~ → **Resolved.** Filters: `type`,
   `is_active` (defaulted true), `has_active_agreement` (toggle), `wilaya` (distinct values).
   `defaultSort('created_at', 'desc')`.
7. ~~**🟡 No `canAccess()`.**~~ → **Resolved.** `fleet.view` / `fleet.manage` seeded;
   `canAccess()`, `canCreate()`, `canEdit()`, `canDelete()` added.
8. ~~**🟡 Deprecated `->actions([...])`**~~ → **Resolved.** Uses `->recordActions([...])`.
9. **🔵 `national_id` has no unique constraint.** `car_owners.national_id` is a nullable
   `string` with no index (`2026_07_28_160000_create_fleet_tables.php:46`). Customers got
   `2026_07_28_171000_add_customer_unique_constraints.php`; owners did not, so the same person
   can be entered twice and their instalments split across two records. Worth confirming
   against REQ-03 whether that was deliberate. **Deferred** — needs a migration.
10. **🔵 Action labels do not translate.** "New Agreement" is set with `->label()` and never
    `->translateLabel()`. Filament's `HasLabel::getLabel()` only calls `__()` when
    `$shouldTranslateLabel` is true (default `false`), and `AdminPanelProvider` configures
    `Field`, `Column`, `Entry`, `Section`, `Table` and `Schema` — **not `Action`**
    (`AdminPanelProvider.php:65-83`). The French and Arabic strings already exist in
    `lang/fr.json` / `lang/ar.json` ("New Agreement" → "Nouveau contrat"), so the dictionary
    entries are dead weight. This affects every custom action in the Fleet cluster; the fix is
    one `Action::configureUsing(fn (Action $a) => $a->translateLabel())` in the panel
    provider, not ten per-file edits. **Unchanged** — cross-cutting fix outside this resource.

## Checklist

- [ ] Decide whether `national_id` should be unique — deferred, needs migration

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
