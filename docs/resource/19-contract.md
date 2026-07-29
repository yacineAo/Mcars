# 19 — Contract (Bookings)

**Model:** `App\Models\Contract` · **Slug:** `/admin/contracts` · **Status:** 🔴 needs work

Closes **REQ-06**, **ADV-01** (e-signature). See
[`../tasks/phase-05-bookings-contracts.md`](../tasks/phase-05-bookings-contracts.md).

## What it is for

The signed rental agreement — the legal document behind a booking. Rendered from a template
into `content_snapshot`, sent to the customer, signed, then closed when the car comes back.
Contracts render in Arabic RTL, so this is one of the few screens where the locale work has
real consequences.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | has filters |
| create | ✅ | |
| view | ❌ | **absent** — the document itself cannot be viewed in the panel |
| edit | ✅ | nothing frozen after signing |
| row actions | ✅ | `render_pdf`, `send`, `sign`, `close` — deprecated `->actions([...])` |
| header / toolbar actions | 🟡 | `CreateAction` |
| relation managers | ❌ | none, though it has `signatures`, `childContracts`, `deposits`, `fines` |
| `canAccess()` | ❌ | absent |

`render_pdf`, `send` and `close` all delegate to `ContractService`, which is right. `sign` does
not — see gap 2.

## Should be

### Index
Extend the filters with a status filter defaulting to **awaiting signature** — the queue the
office chases — plus a date range and a customer filter. Show the contract number, customer,
car, status badge, and `signed_at`.

### Create
A contract is generated from a booking and a template, not typed. Creating one detached from a
booking should be the exception; `content_snapshot` is the rendered document and must come from
`ContractService`, never from a form field.

### View
**Add one, and make it show the document.** A contract resource where you cannot read the
contract is the defect here. The view page should render `content_snapshot` — the frozen text
that was actually agreed, not a re-render from the current template — alongside status,
signature history and the related deposits and fines.

Rendering stored HTML needs care: `content_snapshot` is generated from a template that an
admin edits, so it must be rendered as trusted-but-sanitised markup, and the page must set
direction from the contract's locale rather than the viewer's. Contracts are archival — an
Arabic contract stays RTL when a French-speaking manager opens it.

### Edit
**Freeze once signed.** `content_snapshot`, `terms_version` and `signed_at` are the evidence of
what was agreed; a signed contract that can still be edited is not evidence of anything. After
`Signed`, only `closing_notes` should be writable. Amendments are child contracts — the
`childContracts` relation already exists for exactly that.

### Relations

| Relation | Where | Read-only | Gate | Columns |
|---|---|---|---|---|
| `signatures` | **view** | yes | — | signatory, signed at, method, IP |
| `childContracts` | **view** | yes | — | number, status, created at (amendments) |
| `deposits` | **view** | yes | `reports.view_financials` | amount, status, held at |
| `fines` | **view** | yes | — | notice number, violation date, amount, liability |
| condition reports (via booking) | **view** | yes | — | direction, odometer, fuel, damages |

Condition reports hang off the booking rather than the contract, but the out/in pair is what a
dispute turns on, so surfacing them here is worth the indirection.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| `render_pdf` | row | `! status->is(Draft)` | **nothing** | `ContractService::renderPdf()` | correct |
| `send` | row | `content_snapshot !== null` | **nothing** | `ContractService::send()` | correct |
| `sign` | row | `Draft` \| `AwaitingSignature` | **nothing** | raw `update()` — should be a service | **writes no signature row** — gap 2 |
| `close` | row | see resource | **nothing** | `ContractService` | correct |
| `EditAction` | row | always | **nothing** | — | must freeze once signed |

## Gaps and risks

1. **🔴 No view page.** See View. The document is unreadable in the panel; `render_pdf` produces
   a file, which is not the same as being able to look at what was signed.
2. **🔴 `sign` writes the status transition inline** (`ContractResource.php:96-107`):
   `$record->update(['status' => ContractStatus::Signed, 'signed_at' => now()])`. Every sibling
   action delegates to `ContractService`; this one encodes the signing semantics in the UI. It
   also creates **no signature record**, so `signatures` stays empty and ADV-01's audit trail is
   bypassed entirely — a contract can reach `Signed` with nothing recording who signed it or
   how. Move it to `ContractService::markSigned()` and have it write a signature row.
3. **🔴 No `canAccess()`.** Any staff role can render, send, sign and close contracts.
   Marking a contract signed is a legal assertion and should not be available to every role.
4. **🟡 Nothing frozen after signing** — see Edit.
5. **🟡 No status filter defaulting to the awaiting-signature queue.**
6. **🟡 Deprecated `->actions([...])`.**
7. **🔵 Two PHPStan warnings here are false positives — do not "fix" the code.**
   PHPStan reports "Cannot call method `is()` on string" at `ContractResource.php:82` and a
   strict comparison against null at `:89`. I verified `Contract::casts()` includes
   `'status' => ContractStatus::class` (`Contract.php:52`), so `$record->status->is(...)` is
   correct at runtime, and `content_snapshot` is nullable JSON rather than a non-null string.
   Both are larastan reading column types from the schema instead of `casts()`. Fix with
   `@property ContractStatus $status` and `@property ?array $content_snapshot` docblocks on the
   model — the same remedy applied to `User::$locale` and proposed for
   [`14-cash-session.md`](14-cash-session.md) gap 9. Changing the comparisons would break
   working code.

## Checklist

- [ ] Add a view page rendering `content_snapshot`, with direction from the contract's locale
- [ ] Move `sign` into `ContractService::markSigned()` and write a `signatures` row
- [ ] Add `canAccess()`; restrict sign and close
- [ ] Freeze `content_snapshot` / `terms_version` / `signed_at` once signed
- [ ] Add the 5 relation managers, money ones gated
- [ ] Add a status filter defaulting to awaiting signature; add date and customer filters
- [ ] Add `@property` docblocks for `status` and `content_snapshot`
- [ ] `->actions(` → `->recordActions(`

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/BookingTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/ContractResource.php app/Models/Contract.php
```

By hand: render a contract in Arabic and confirm the view page and the PDF are both RTL, and
that a French-speaking user opening the same contract still sees it RTL. Sign a contract and
confirm a signature row is written. Then attempt to edit the signed contract's text and confirm
it is refused.
