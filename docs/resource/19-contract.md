# 19 — Contract (Bookings)

**Model:** `App\Models\Contract` · **Slug:** `/admin/contracts` · **Status:** ✅ audited — fine

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
| index | ✅ | status filter **defaulting to awaiting signature**, customer filter, generated date range |
| create | ✅ | **generated** from booking + template via `ContractService::generate()` — never typed |
| view | ✅ | renders the stored `content_snapshot` (sanitised, `dir` from the snapshot's locale) + identity, signatures, amendments, deposits, fines, condition reports |
| edit | ✅ | every term field disabled once signed (ADR-005); only `closing_notes` stays writable |
| row actions | ✅ | `render_pdf`, `send`, `sign`, `close`, Edit, Delete — `->recordActions([...])`, all gated |
| header / toolbar actions | ✅ | `CreateAction` |
| relation managers | ✅ | 5, money ones gated |
| `canAccess()` | ✅ | `bookings.view` |

## Gaps found and fixed

1. **No view page.** Added `ViewContract`: the stored document (template body + identity, car,
   period and pricing tables) rendered from `content_snapshot` — the frozen text actually
   agreed, never a re-render from the current template. The snapshot HTML is **sanitised**
   before rendering: a DOM whitelist drops every attribute and any tag outside a fixed set, so
   a template edit cannot smuggle a script or an event handler onto the panel. Direction comes
   from `Contract::direction()` (`content_snapshot['locale'] === 'ar'` → `rtl`), so an Arabic
   contract stays RTL when a French-speaking manager opens it. The `document_hash` shown
   against the document ties the screen to the stored PDF.
2. **`sign` bypassed the service and wrote no signature row.** Now
   `ContractService::markSigned($contract, $signerRole, $signerName, $by, ?Model $signer)`:
   refuses `Signed`/`Closed`/`Cancelled`, creates the `ContractSignature` row
   (`method: InPersonPaper`, name snapshot, `document_hash`, `signed_at`) and only then moves
   the status. ADV-01's audit trail is real: a `Signed` contract always records who signed it,
   how, and **who at the desk witnessed it** (`signed_by_id` — null for OTP signatures, where
   nobody vouches). The witness write and the status flip happen in one transaction with the
   contract row locked, so two desks cannot record two signatures for the same contract.
3. **No `canAccess()`, nothing gated.** Access gates on `bookings.view` (the accountant reads
   contracts but never freezes one). `canCreate`/`canEdit`/`render_pdf`/`send`/`close` gate on
   `bookings.operate`. **Signing is its own permission** — `contracts.sign` — because a
   signature locks a price into a legal document: an accountant who can read must never be the
   one who signs. Delete is `bookings.manage` **and** only a `Draft`, mirroring the booking
   rule. `contracts.sign` ships as a migration
   (`2026_08_01_000002_seed_contracts_sign_permission.php`), not just a seeder entry, so an
   existing deployment does not lose the action until it is seeded (same guarantee as
   `bookings.operate`).
4. **Nothing frozen after signing.** `Contract::isLocked()` — false only for
   `Draft`/`AwaitingSignature` — disables every term field on edit. Amendments are child
   contracts via `ContractService::amend()`, which the `childContracts` relation surfaces.
5. **No awaiting-signature queue.** Status filter defaults to `AwaitingSignature`; customer
   and generated-date-range filters added (half-open bounds, Africa/Algiers).
6. **Deprecated `->actions([...])`.** Migrated to `->recordActions([...])`.
7. **PHPStan `is()` / null-comparison warnings.** Fixed the model as the audit prescribed:
   `@property ContractStatus $status` and `@property array|null $content_snapshot` docblocks
   on `Contract`.

### Decisions taken while fixing

- **`close` needs the check-in report.** `ContractService::close($contract, $checkin, $by)`
  was already report-based, but the action typed notes into the void. The action now requires
  picking the booking's check-in `ConditionReport`; `closing_notes` and `has_damages` come
  **from the report**, not from what the operator types — the close-out is evidence, and the
  two can no longer disagree. The pairing is enforced in the service: a report that belongs to
  another booking is refused with a `RuntimeException` (the UI select constrains it, but a
  crafted request must not close one booking on another's report). `close` now also accepts
  `Signed` contracts (a signed contract can be closed on return), while `markSigned` still
  refuses anything past `AwaitingSignature`.
- **`send` has a visible guard.** `content_snapshot !== null` (unchanged) plus
  `Draft|AwaitingSignature`, so a sent contract is not re-sent into the void.
- **The booking is immutable once pinned.** The create form's booking picker only offers
  bookings without a contract (the service throws otherwise), and the field disables on edit:
  re-pointing a contract at another booking would desync the document from the booking it
  quotes.
- **`conditionReports` via `hasManyThrough`.** The relation manager surfaces the booking's
  condition reports through `Contract::conditionReports()` (a `hasManyThrough` — read-only
  list only), because the out/in pair is what a dispute turns on.

## Permission map

| Permission | Guards |
|---|---|
| `bookings.view` | resource access, View page |
| `bookings.operate` | create, edit, `render_pdf`, `send`, `close` |
| `contracts.sign` | the `sign` action — `SignatureService` OTP verification is unchanged |
| `bookings.manage` | delete (draft only) |
| `reports.view_financials` | the deposits relation manager |

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/ContractResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ContractTemplateResourceTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/ContractResource.php app/Models/Contract.php app/Services/Booking/ContractService.php
```

By hand: render a contract in Arabic and confirm the view page and the PDF are both RTL, and
that a French-speaking user opening the same contract still sees it RTL. Sign a contract and
confirm a signature row is written. Then attempt to edit the signed contract's text and confirm
it is refused.
