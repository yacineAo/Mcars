# 35 — Branch (Settings)

**Model:** `App\Models\Branch` · **Slug:** `/admin/branches` · **Status:** ✅ done

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

## Current state (after Round 35)

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | name, code (badge), `manager.name`, `users_count` (`->counts('users')`), `wilaya` (display label), `is_default` badge on the one default row, `is_active` badge; filters: `TernaryFilter` on `is_active` (defaults to active) + `SelectFilter` on `wilaya`; `defaultSort('name')` |
| create | ✅ | sectioned: **Identity** (name, code) · **Location** (address, city, wilaya, timezone) · **Contact** (phone, email) · **Management** (manager, `is_active`) · **Notes**; no `is_default` field |
| view | ❌ | deliberately — see the old "Should be" §View, unchanged |
| edit | ✅ | same sectioned form; `code` **disabled** once any `sequences` row exists for the branch; guarded delete header action |
| row actions | ✅ | `EditAction` + **Make default** + **Deactivate/Reactivate**, via `->recordActions([...])` |
| header / toolbar actions | ✅ | `CreateAction` (index) only — **no bulk actions**; delete is the guarded header action on edit |
| relation managers | ✅ | `UsersRelationManager` on edit: read-only `users` (`hasMany` — the staff whose home branch this is), name/email/roles/is_active, gated on `users.manage` |
| `canAccess()` | ✅ | `branches.view_all` (`BranchResource.php`) — super_admin + manager, unchanged |

## Decisions taken (Round 35)

1. **Deletion is a guarded soft delete, never bulk.** `DeleteBulkAction` and the unguarded
   `DeleteAction` are gone (gap 1 + 2). The only delete is a confirmation action on the edit
   page that delegates to `BranchService::delete()`, which refuses:
   - the **default** branch — the flag must land somewhere;
   - any branch with **rows still pointing at it**. The guard scans
     `information_schema.columns` for every `branch_id` column and counts — schema-driven, so a
     branch_id added by a later phase is picked up without touching the service. Because every
     branch_id foreign key is `nullOnDelete` (the append-only ledger included), a soft-deleted
     branch with live rows would silently un-attribute history; deletion is for a wound-down
     branch only, and the sibling Deactivate/Reactivate action is the path operators actually
     want. `sequences.branch_id` stays `ON DELETE CASCADE` (it never fires on soft delete).
2. **The single-default index ignores tombstones.** `2026_08_18_000000` recreates
   `branches_single_default` as `ON branches (is_default) WHERE is_default AND deleted_at IS NULL`,
   so a soft-deleted default branch stops blocking its successor. `BranchService::makeDefault()`
   is the only writer of the flag: it clears `is_default` **withTrashed()** (the tombstone must
   not keep claiming the title) and promotes the new holder inside one transaction, then logs an
   activity row `made_default` with the actor. See the regression test below.
3. **`code` is validated and frozen.** → `maxLength(8)`, `alphaDash()`, and
   `App\Rules\UniqueBranchCode` — case-insensitive uniqueness, as a `ValidationRule` so a
   duplicate surfaces as a form error instead of the `branches_code_unique` QueryException (gap 3).
   Once a `sequences` row exists the field is `disabled()` (non-dehydrated, server-side), because
   renaming the code would split one year's documents into two prefixes with no gap to signal it.
   Frozen or not, the `setCodeAttribute` mutator still upper-cases.
4. **`wilaya` is a real vocabulary.** `App\Enums\Wilaya` — all 58, backed enum with
   `HasEnumMeta`, labels via a `getLabel()` match (proper nouns, not translated). Migration
   `2026_08_19_000000` adds check constraints on `branches`, `customers` and `car_owners`
   (`wilaya IS NULL OR wilaya IN (...)`), normalising only the three values the old hardcoded
   Select could have stored — anything else stops the migration loudly, which is honest
   pre-go-live. The three resources (and their view infolists and table filters) all use
   `Wilaya::options()` / `Wilaya::tryFrom()` for labels. Factories and seeders that stored
   free text were realigned to enum values (gap 7).
5. **`timezone` is on the form** as an Africa-first Select defaulting to `Africa/Algiers`
   (gap 8) — a non-Algiers branch no longer silently runs on Algiers time.
6. **`manager_user_id` says manager, grants nothing** (helper text) — the capability is the
   `manager` role, not the column.
7. **No `HasAuditColumns`** (gap 9): left as designed; `LogsActivity` already answers "who did
   what to this branch", and `branches.created_by` was never asked for.

## Gaps and risks (closed)

1. **🔴** Unguarded delete + no `deleted_at` index predicate → *fixed* by 1 + 2 above. Regressions
   covered in `tests/Feature/BranchResourceTest.php`:
   `it('promotes a new default after the previous one was soft-deleted')`,
   `it('refuses to delete the default branch')`,
   `it('refuses to delete a branch that still has rows pointing at it')`.
2. **🔴** Nothing checked dependent rows → *fixed* by the schema-driven count in `BranchService`.
3. **🟡** `code` 10-into-8 + pre-mutator uniqueness → *fixed* (decision 3).
4. **🟡** `is_default` form toggle → *fixed* (decision 2; the create form asserts
   `assertFormFieldDoesNotExist('is_default')`).
5. **🟡** Empty filters / no sort / no manager or staff column → *fixed*.
6. **🟡** Deprecated `->actions()` → *fixed* (`->recordActions([...])`).
7. **🔵** wilaya 3-of-58 + three representations → *fixed* (decision 4).
8. **🔵** `timezone` absent → *fixed* (decision 5).
9. **🔵** No `HasAuditColumns` → left as designed (decision 7).

A remaining, explicit non-goal: `branch_user` pivot membership stays on the user record
(`UserResource` → BranchUsersRelationManager), not here — see the old "Should be" § Relations.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/BranchResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/FoundationTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/BranchResource.php app/Services/BranchService.php app/Enums/Wilaya.php
```

Manual check (throwaway DB): soft-delete the default branch, promote another → succeeds; create a
second branch, attach a staff row to it, attempt delete → refused with a notification; the default
branch carries no delete action on its edit page and no deactivate action on its list row.