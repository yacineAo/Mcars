# 17 — ContractTemplate (Bookings)

**Model:** `App\Models\ContractTemplate` · **Slug:** `/admin/contract-templates` · **Status:** 🟡 partial

Supports **REQ-06**. See [`19-contract.md`](19-contract.md), which consumes these.

## What it is for

The boilerplate a contract is rendered from, per locale and contract type. An admin edits the
terms once; every contract rendered afterwards uses the new text while contracts already
rendered keep their own `content_snapshot`. That separation is what makes editing a template
safe, and it is worth confirming rather than assuming.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | no filters |
| create | ✅ | |
| view | ❌ | see below |
| edit | ✅ | |
| row actions | ✅ | `set_default` (`ContractTemplateResource.php:63`), Edit |
| header / toolbar actions | 🟡 | `CreateAction`; **`DeleteBulkAction`** |
| relation managers | ❌ | none needed |
| `canAccess()` | ❌ | absent |

## Should be

### Index
Filter by `locale` and `contract_type`, and show which template is default for each combination.
With three locales and multiple contract types the list becomes a matrix, and "which template
will actually be used for an Arabic long-term rental" should be answerable at a glance.

### Create
The body is the substance. It needs a placeholder reference visible while editing — an admin
writing terms must know the available tokens (customer name, plate, dates, total) without
reading the service source. Templates for `ar` must be authored RTL.

### View
Worth adding as a **preview**: the template rendered with sample data, so an admin can see the
result before making it default. Editing legal boilerplate blind and discovering the mistake on
a customer's signed contract is the failure this prevents.

### Edit
`locale` and `contract_type` should freeze once the template has rendered contracts — they are
the selection key, and changing them silently redirects which template future contracts pick up.
The body stays editable; that is the point.

`terms_version` should increment on a body change rather than being hand-edited, so a contract's
stored `terms_version` reliably identifies the text that produced it.

### Relations
None. Rendered contracts reference the template, but that list belongs on
[`19-contract.md`](19-contract.md) filtered by template — not as a relation manager on
boilerplate.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `set_default` | row | always | **nothing** | unverified — check it clears the previous default | gap 3 |
| `EditAction` | row | always | **nothing** | — | freeze locale + type once contracts exist |
| `DeleteBulkAction` | toolbar | always | **nothing** | — | **remove** — gap 1 |

## Gaps and risks

1. **🔴 `DeleteBulkAction` on templates that contracts were rendered from.** Even soft-deleted,
   a template referenced by `terms_version` history should not vanish — it is the provenance of
   a legal document. Remove the bulk action; refuse single delete where contracts reference it,
   and deactivate instead.
2. **🔴 No `canAccess()`.** This is the contract boilerplate: any staff role can currently
   rewrite the legal terms the business rents cars under, and set their version as default. It
   should be tightly restricted — narrower than most Settings resources.
3. **🟡 Only one default enforced?** `set_default` exists; confirm the service or the action
   clears the previous default for the same locale/type pair. Two defaults for one combination
   makes template selection arbitrary.
4. **🟡 No preview** — see View.
5. **🟡 No locale / type filters**, so the matrix is unreadable.
6. **🟡 `terms_version` hand-managed** — see Edit.
7. **🔵 No placeholder reference** for the author.

## Checklist

- [ ] Remove `DeleteBulkAction`; refuse delete where contracts reference the template
- [ ] Add `canAccess()` — restrict tightly
- [ ] Confirm `set_default` clears the previous default for the same locale and type
- [ ] Add a preview page rendering the template with sample data
- [ ] Add `locale` / `contract_type` filters; show the default per combination
- [ ] Freeze `locale` and `contract_type` once contracts exist
- [ ] Auto-increment `terms_version` on a body change
- [ ] Show the available placeholders while editing
- [ ] Verify an Arabic template renders RTL in both the preview and the PDF

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

By hand — the important one: render a contract, then edit the template body, and confirm the
already-rendered contract's text is **unchanged**. If it changes, `content_snapshot` is not
doing its job and that is a 🔴 for [`19-contract.md`](19-contract.md) too.
