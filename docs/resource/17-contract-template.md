# 17 — ContractTemplate (Bookings)

**Model:** `App\Models\ContractTemplate` · **Slug:** `/admin/contract-templates` · **Status:** ✅ audited — fine

Supports **REQ-06**. See [`19-contract.md`](19-contract.md), which consumes these.

## What it is for

The boilerplate a contract is rendered from, per locale. An admin edits the terms once;
every contract rendered afterwards uses the new text while contracts already rendered keep
their own `content_snapshot`. That separation is what makes editing a template safe, and it
is confirmed below.

> **Correction to the original audit.** The audit assumed a `contract_type` column and an
> `is_default` per locale/type combination. Neither exists and neither is wanted:
> `contract_templates` has no `contract_type` column (schema doc line 304) and the
> `ContractType` enum (`cdi | cdd | trial | freelance`) describes **employee** contracts,
> not rentals. **Locale is the only selection key.** Filters and "default" semantics are
> per-locale.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | locale filter, sorted by name, `withExists('contracts')` for the delete guard |
| create | ✅ | starts at terms version 1.0, demotes the previous default |
| view | ✅ | preview page — body rendered with sample data, RTL for `ar` |
| edit | ✅ | body change bumps `terms_version`; locale freezes once contracts exist |
| row actions | ✅ | `set_default`, View, Edit, Delete — writes gated on `bookings.manage` |
| header / toolbar actions | ✅ | `CreateAction`; **no bulk actions** |
| relation managers | ❌ | none needed |
| `canAccess()` | ✅ | `bookings.view` to read, `bookings.manage` to write |

## What was fixed

### Index
`locale` SelectFilter (from `Locale`), `defaultSort('name')`, and the `is_default` icon
answers "which template is used for an Arabic rental" at a glance — the three locales, one
default each.

### Create / Edit
The body is the substance: the textarea lists the **available placeholders** as helper text
(`{{customer_name}}`, `{{car_description}}`, `{{pickup_at}}`, `{{expected_return_at}}`,
`{{total_amount}}` and friends — see `App\Support\ContractTemplatePreview`, the single
reference), above the warning that **nothing substitutes them yet** (see Gaps).

`terms_version` is **auto-managed**: the field is disabled *and* not dehydrated, so it
cannot be submitted at all; a body change on the edit page bumps it (`1.0` → `1.1`) — so a
contract's stored `terms_version` reliably identifies the text that produced it. A value
outside `major.minor` starts a new major series rather than growing a suffix, because the
column is `varchar(16)`.

`locale` **freezes once contracts exist** — the selection key cannot silently redirect
which template future contracts pick up. The freeze is both UI (`disabled`) and effective
server-side (the field is not dehydrated), and a test submits a locale change against a
frozen template to prove the second half rather than assuming it.

`is_default` exclusivity is **owned by `ContractService::setDefaultTemplate()`**, not by the
pages that trigger it. All three entry points — `set_default`, Create, Edit — call it:

- **Scoped by branch *and* locale.** A template carries a `branch_id`; one branch promoting
  its own template must not strip another branch's default.
- **Keyed on the saved record, not the form.** The demotion runs after the record is written,
  so an edit that moves a template to another locale demotes the locale it is *joining*.
  Reading the locale off the record beforehand demoted the one it was leaving and left the
  new locale with two defaults — the bug that motivated moving the rule out of the pages.
- **Atomic.** The panel does not run pages in a transaction, so Create/Edit wrap the save and
  the demotion together; a failed insert can no longer leave a locale with no default at all.

### View
A preview page renders the body **with sample data substituted** — an admin sees the result
before making it default, instead of discovering a mistake on a customer's signed contract.
An Arabic template renders `dir="rtl" lang="ar"`, so RTL preview is verifiable before the
PDF work in 19.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `set_default` | row | not already default **and** `bookings.manage` | row `visible` | `ContractService::setDefaultTemplate()` | demotes the previous default for the same branch + locale |
| `ViewAction` | row | always | resource `canAccess` | — | preview page |
| `EditAction` | row | `bookings.manage` | row `visible` + page `canEdit()` | — | locale frozen once contracts exist |
| `DeleteAction` | row | `canDelete()` — `bookings.manage` and nothing rendered from it | row `visible` + page `canDelete()` | — | refuses when `hasRenderedContracts()` |
| `DeleteBulkAction` | toolbar | — | — | — | **removed** |

> **Table actions carry their own guard on purpose.** Filament's `EditAction` and
> `DeleteAction` do **not** consult `Resource::canEdit()` / `canDelete()` — those authorize
> the *pages*, and a table action runs in place over Livewire without visiting one. Verified
> the hard way: with read access widened to `bookings.view` and no row `visible`, a
> receptionist could delete a template straight from the list. Any resource that widens
> `canAccess()` beyond its write permission has to re-check on the row; a test calls the
> delete action as a reader and asserts the record survives.

### `canAccess()`
`bookings.view` to read, `bookings.manage` to write — the same split as the rest of the
bookings catalogue, and what `RolePermissionSeeder` already documents for these two
permissions. Every staff role except the maintenance officer can *read* the terms (a
receptionist explaining them to a customer needs them); rewriting the legal boilerplate the
business rents cars under is SuperAdmin and Manager only.

## Placeholders

| Token | Meaning |
|---|---|
| `{{customer_name}}` | Customer full name |
| `{{customer_phone}}` | Customer phone |
| `{{customer_city}}` | Customer city |
| `{{car_description}}` | Brand + model + year |
| `{{car_registration_number}}` | Plate |
| `{{pickup_at}}` | Rental start |
| `{{expected_return_at}}` | Expected return |
| `{{daily_rate}}` | Daily rate |
| `{{days_count}}` | Number of days |
| `{{total_amount}}` | Booking total |
| `{{security_deposit_amount}}` | Deposit |
| `{{booking_reference}}` | Booking reference |

Source of truth: `App\Support\ContractTemplatePreview`. Actual substitution at render time
is 19's job (see gap 5 in `19-contract.md`).

## Which template a contract uses

`ContractService::resolveTemplate($locale, $branchId)` — one query, deterministic:

1. active templates in that locale, from **this branch or global** (`branch_id null`);
2. an explicit `is_default` wins;
3. between two, the branch's own beats the global one;
4. `id` breaks the remaining tie.

It replaces two unordered `->first()` calls that could return either of two rows and ignored
the branch entirely. Note `BelongsToBranch` fills `branch_id` on create whatever you pass,
so genuinely global templates arrive by seed or import, never through the form.

## Gaps and risks

- **🔴 The placeholders are not actually substituted when a contract is rendered.**
  `ContractService::renderHtml()` dumps `template_body` verbatim, so `{{customer_name}}`
  today reaches the PDF. This belongs to `19-contract.md` (its rendering gap); this file
  guarantees the preview and the authoring reference, and — since a resolved preview
  otherwise reads as proof the tokens work — carries `ContractTemplatePreview::warning()`
  on both the edit form and the preview page until 19 lands.
  Whoever implements it: the tokens are flat (`customer_name`) while
  `ContractService::buildSnapshot()` emits nested (`customer.name`). Mapping one onto the
  other is part of the job, and renaming a token silently breaks every template already
  written against it.
- **🟡 No per-template usage history.** Rendered contracts reference the template, but the
  list is on `19-contract.md` (filtered by template) rather than a relation manager here —
  deliberate: the audit wanted no relation manager on boilerplate.
- **🔵 Other resources likely share the table-action authorization gap** described above.
  This one is closed; `ExtraResource` and its siblings were not audited for it here, and
  they are only safe while `canAccess()` equals their write permission.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ContractTemplateResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
```

By hand: render a contract, then edit the template body, and confirm the already-rendered
contract's `content_snapshot` is **unchanged** — the snapshot, not the template, is the
evidence of what was agreed. Also: set a template default for `fr`, create another `fr`
template with the default toggle on — the first must lose default.
