# 35 — Branch (Settings)

**Model:** `App\Models\Branch` · **Slug:** `/admin/branches` · **Status:** 🔴 needs work

Serves **ADV-06**. Read [`../06-design-decisions.md`](../06-design-decisions.md) ADR-004 and
[`../08-multi-branch-retrofit.md`](../08-multi-branch-retrofit.md) before changing anything
here; see also [`../tasks/phase-10-portals-audit-backups.md`](../tasks/phase-10-portals-audit-backups.md).

## What it is for

The registry of physical locations. Almost nothing in the business is *read* through this
screen — its importance is that everything else depends on the rows in it. Every operational
and financial insert fills `branch_id` from `BelongsToBranch::resolveBranchId()`, whose last
resort is `Branch::defaultId()`; and every document number embeds `branches.code`
(`SequenceGenerator::next($key, $branch->id, $branch->code)` → `CTR-MAIN-2026-000123`). A
super_admin opens it once at setup and then only to add a location.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 5 columns (name, code, city, `is_active`, `is_default`); `->filters([])` **empty**; no default sort |
| create | ✅ | flat 12-field form, no sections |
| view | ❌ | — |
| edit | ✅ | same form; `created_at` Placeholder hidden on create; nothing frozen |
| row actions | ✅ | `EditAction` only, via deprecated `->actions([...])` |
| header / toolbar actions | ✅ | `CreateAction` (index), `DeleteAction` (edit), `DeleteBulkAction` |
| relation managers | ❌ | none — `users` is unreachable from here |
| `canAccess()` | ✅ | `branches.view_all` (`BranchResource.php:44`) |

The gate is right here, and worth contrasting with [`33-user.md`](33-user.md): on *this*
resource `branches.view_all` is literally the subject matter, and the role set it resolves to
(super_admin + manager) matches phase-01's "`BranchResource` restricted to `manager` /
`super_admin`". PHPStan is clean.

Note that `config('branches.enabled')` is **false** in the live environment, so `BranchScope` is
currently a no-op — despite phase-10 marking the flip as done. Nothing on this screen depends on
it, but [`36-alert-rule.md`](36-alert-rule.md) gap 1 does.

## Should be

### Index

Add `manager.name`, a `users_count` (via `->counts('users')`, not a per-row query), and
`wilaya`. Filters: `TernaryFilter` on `is_active` defaulting to active, `SelectFilter` on
`wilaya`. `defaultSort('name')`. `is_default` should read as a badge on the one default row
rather than a boolean icon on every row — there is exactly one, by database constraint.

### Create

Section it: **Identity** (name, code) · **Location** (address, city, wilaya, timezone) ·
**Contact** (phone, email) · **Management** (manager, `is_active`) · **Notes**.

`code` must be validated to `maxLength(8)`: the column is `varchar(8)` and the form allows
**10**, so a 9- or 10-character code passes validation and fails in Postgres. It also needs
`->rule('alpha_dash')` or similar and case-insensitive uniqueness — `Branch::setCodeAttribute()`
upper-cases the value *after* Laravel's `unique` rule has run, so entering `main` passes
validation and then collides with `MAIN` on `branches_code_unique`.

`timezone` is fillable and has a database default of `Africa/Algiers`, but is absent from the
form; add it (read-only would be fine) so a second-wilaya branch is not silently on Algiers
time. `wilaya` is a Select hardcoded to **three** of Algeria's 58, while `CustomerResource` and
`CarOwnerResource` use a free-text `TextInput` for the same field — pick one and apply it in all
three places. There is no `Wilaya` enum yet; adding one is the honest fix.

`is_default` must come out of the form entirely. It is a property of the *set*, not of a row,
and a toggle invites the failure in gap 1. Make it an action (see Actions).

`manager_user_id` is an unfiltered user Select. Worth stating in the helper text that naming
someone branch manager grants **nothing** — authorization is the `manager` role and the four
seeded permissions, not this column.

### View

Not needed. `Branch` declares two relations (`manager`, `users`) and a dozen scalar columns;
an infolist would restate the edit form. The one thing an admin wants before deactivating a
branch — who and what is attached to it — is better served by the counts on the index and the
`users` table on edit.

### Edit

**`code` must freeze once any document has been numbered under it.** The sequence counter is
keyed on `(key, branch_id, year)`, not on the code, so renaming the code does not reset the
counter but does change the prefix — splitting one year's contracts into `CTR-MAIN-2026-…` and
`CTR-ALGR-2026-…` with no gap to signal it. Freeze it when a `sequences` row exists for the
branch, or unconditionally after creation.

`is_default` is not editable here (see above). Everything else stays editable.

### Relations

One, and it belongs on **edit**: **`users`** (`hasMany`), read-only, showing name, email, roles
and `is_active`. It is the answer to "who am I about to cut off", which is the question an admin
has immediately before deactivating a branch. No gate — no money on this screen.

The `branch_user` pivot is the *other* half of branch membership and has no UI anywhere; that
belongs on the user record, not here — see [`33-user.md`](33-user.md) Relations. Cars, bookings
and transactions per branch are reports, and belong in `ReportResource`, not on a relation
manager over an unbounded table.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| Make default | row | `! $record->is_default` and active | `branches.view_all` | `BranchService` | clears the old flag and sets the new one in one transaction |
| Deactivate / reactivate | row | on `is_active` | `branches.view_all` | `BranchService` | the intended alternative to delete |
| Delete | row / edit header | never on the default branch, never when rows point at it | `branches.view_all` | `BranchService` | see gap 1 |

## Gaps and risks

1. **🔴 Deleting the default branch is unrecoverable through the UI, and the UI offers it.**
   `EditBranch.php:18` has an unguarded `DeleteAction` and the table an unguarded
   `DeleteBulkAction`. Nothing checks `is_default` — not the resource, not the model, and no
   observer or policy exists. Because `Branch` soft-deletes, the tombstone keeps
   `is_default = true`, and the partial unique index has **no `deleted_at` predicate**:
   `CREATE UNIQUE INDEX branches_single_default ON branches (is_default) WHERE is_default`
   (read from `pg_indexes`). Verified in a rolled-back transaction: after soft-deleting the
   default branch, promoting the surviving branch fails with
   `duplicate key value violates unique constraint "branches_single_default"`. Meanwhile
   `Branch::defaultId()` honours the SoftDeletes scope and returns **null**, so
   `BelongsToBranch::resolveBranchId()` stops filling `branch_id` for every user without a home
   branch. One click leaves the system with no default branch and no way to appoint one.
   Fix all three layers: guard the action, add `AND deleted_at IS NULL` to the index, and give
   `BranchService` a `makeDefault()` that is the only writer of the flag.
2. **🔴 Nothing checks whether rows point at the branch.** Soft deletion means the foreign keys
   never fire, so cars, bookings, transactions and cash sessions keep pointing at a branch that
   no longer appears in any list — silently, with no error. Delete must refuse while dependent
   rows exist; deactivate is what the operator actually wants. (Related, and latent because no
   force-delete UI exists: `sequences.branch_id` is `ON DELETE CASCADE`, so a hard delete would
   drop the branch's document counters and restart numbering at 1.)
3. **🟡 `code` accepts 10 characters into a `varchar(8)` column**, and `unique` runs before the
   upper-casing mutator. Both surface as raw database errors.
4. **🟡 `is_default` is a form toggle.** Two admins editing two branches can each tick it; one
   save wins and the other is a `QueryException`. It is a set-level invariant and needs an
   action, not a field.
5. **🟡 Empty `->filters([])`, no default sort, no manager or user-count column.**
6. **🟡 Deprecated `->actions([...])`** — panel-wide finding 3 in [`README.md`](README.md).
7. **🔵 `wilaya` offers 3 of 58**, and disagrees with the two other resources that store the
   same field.
8. **🔵 `timezone` is fillable but not on the form.**
9. **🔵 No `HasAuditColumns`.** `Branch` uses `LogsActivity` but not `HasAuditColumns`, and
   `branches` has no `created_by_id` / `updated_by_id`. Deliberate or not, "who created this
   branch" is answerable only through the activity log. Leave it unless the audit trail is
   asked to answer it cheaply.

## Checklist

- [ ] Guard delete: refuse on the default branch and while dependent rows exist; prefer
      deactivate
- [ ] Migration: add `AND deleted_at IS NULL` to `branches_single_default`
- [ ] Move `is_default` out of the form into a `BranchService::makeDefault()` action, and add a
      test that soft-deleting the default branch then promoting another one succeeds
- [ ] `code`: `maxLength(8)`, case-insensitive uniqueness, frozen on edit once `sequences` rows
      exist
- [ ] Add a read-only `users` relation manager on edit, and `users_count` + `manager.name` to
      the index
- [ ] Add the `is_active` and `wilaya` filters and `defaultSort('name')`
- [ ] Add `timezone` to the form; decide the `wilaya` representation and apply it to Customer
      and CarOwner too
- [ ] Section the form; `->actions(` → `->recordActions(`
- [ ] Note in helper text that `manager_user_id` grants no permissions

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/FoundationTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/SequenceGeneratorTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/BranchResource.php app/Filament/Admin/Resources/BranchResource
```

By hand, on a throwaway database: create a second branch, delete the default one from its edit
page, then try to make the second one default. Today the first step succeeds and the second
fails with a unique-constraint error, and creating any record afterwards leaves `branch_id`
null. Both steps must succeed after the fix.
