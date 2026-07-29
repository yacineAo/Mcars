# 33 — User (Settings)

**Model:** `App\Models\User` · **Slug:** `/admin/users` · **Status:** 🔴 needs work

Closes **REQ-20** (RBAC). See
[`../tasks/phase-01-auth-roles-panels.md`](../tasks/phase-01-auth-roles-panels.md) and the
role → visibility matrix in [`../02-filament-panels.md`](../02-filament-panels.md).

## What it is for

The screen that creates staff logins and decides what each of them may do. A super_admin
opens it to onboard a receptionist, park a leaver, or move someone between roles. Because
`User::canAccessPanel()` admits any holder of a `UserRole` and every capability in the
panel resolves from the roles ticked in this form, this is the access-control surface of
the whole system — nothing else grants anything.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 6 columns; `->filters([])` **empty**; no default sort |
| create | ✅ | flat 8-field form, no sections |
| view | ❌ | — |
| edit | ✅ | same form; `password` `->hiddenOn('edit')`; nothing frozen |
| row actions | ✅ | `EditAction` only, via deprecated `->actions([...])` |
| header / toolbar actions | ✅ | `CreateAction` (index), `DeleteAction` (edit), `DeleteBulkAction` |
| relation managers | ❌ | none — `branchUsers` has no UI anywhere |
| `canAccess()` | 🟡 | present, but on `branches.view_all` (`UserResource.php:43`) — see gap 2 |

Three things are already right and should not be undone. The sensitive columns are handled
correctly: `#[Hidden(['password', 'remember_token', 'two_factor_secret',
'two_factor_recovery_codes'])]` on the model (`User.php:31`), and **none of the four appears
in the form or the table** — checked field by field. `password` is cast `hashed` and hidden on
edit. `User::withoutBranchScope()` returns true, honouring phase-01's "do not scope `users` by
branch — an admin can lock themselves out". And the `locale` Select is exactly what it should
be: `Locale::options()`, required, now backed by the CHECK constraint added in migration
`2026_08_06_000000`, so the form and the database agree on the same three values. PHPStan is
clean on the resource and all three pages.

## Should be

### Index

Columns, in order: `name` (searchable), `email` (searchable), roles as badges rendered
through `UserRole` labels, `branch.name`, `is_active` icon, `last_login_at`. Eager-load
`roles` and `branch` — the roles badge is a per-row relation lookup today.

Filters the table has none of: `SelectFilter` on role, `TernaryFilter` on `is_active`
defaulting to active only, and a branch filter visible only with `branches.view_all`.
`defaultSort('name')`.

### Create

Section it: **Identity** (name, email, phone, whatsapp) · **Access** (roles, `is_active`,
initial password) · **Placement** (branch, branch assignments) · **Preferences** (locale,
digest). `phone` needs `->unique(ignoreRecord: true)`: the column carries
`users_phone_unique` and the form does not validate it, so a duplicate reaches Postgres as a
raw `QueryException`.

Roles must not be a plain unfiltered Select — see gap 1. Nothing here may set
`two_factor_secret`, `two_factor_recovery_codes`, `remember_token` or `created_by_id`.

### View

Not needed for the fields — a user is eight of them, and an infolist would restate the edit
form. **Proposal:** if "what has this person done" is ever answered inside the panel it belongs
on a view page here as a read-only slice of `activity_log`, which requires adding Spatie's
`CausesActivity` to `User` first. Until then it is answered by
[`38-activity-log.md`](38-activity-log.md) filtered by causer.

### Edit

`email` and the profile fields stay editable. What must change is who may edit **which**
fields: the acting user's own roles must be untouchable from their own record, and role
assignment generally belongs to an action with an audit trail rather than a multi-select
buried in a form (gap 1). Password stays out of the form; a reset is an action.

### Relations

One relation is worth attaching, and it is missing: **`branchUsers`** (`belongsToMany` with
an `is_primary` pivot), the table `User::accessibleBranchIds()` reads first. It has no UI at
all, so multi-branch assignment can only be done in tinker. It belongs on **edit** — the
office maintains it in place — showing branch name, code and `is_primary`, not read-only.

`roles` stays a form field rather than a relation manager: it is a short fixed list, and it
needs the guard in gap 1 more than it needs a tab. No other relation belongs here.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| Assign roles | row + edit header | never on `$record->is($actingUser)` | `users.manage` | `UserService` | replaces the in-form Select; records who changed what |
| Reset password | row + edit header | always | `users.manage` | `UserService` | sets `must_change_password`; no path exists today |
| Deactivate / reactivate | row | on `is_active` | `users.manage` | `UserService` | the intended replacement for delete |
| Delete | — | remove | — | — | see gap 3 |

## Gaps and risks

1. **🔴 A manager can promote themselves to super_admin.** `canAccess()` requires
   `branches.view_all`, which `RolePermissionSeeder` grants to super_admin **and manager**.
   The roles field (`UserResource.php:72-75`) is an unfiltered
   `->relationship('roles', 'name')->multiple()`, so a manager opens their own record, ticks
   `super_admin`, and saves. Nothing filters the options, nothing excludes the acting user's
   own record, and there is no policy to stop it: `Gate::getPolicyFor(User::class)` returns
   **null** — `app/Policies/` does not exist and `shield:generate` was never wired into a
   seeder, despite phase-01 marking it done. Verified live: `UserResource::canAccess()` is
   true for `manager@mcars.dz`.
2. **🔴 `branches.view_all` is the wrong gate.** It means "see other branches' data", not
   "administer accounts". The role set it resolves to today (super_admin + manager) happens
   to match the Settings & Access row of the matrix in `../02-filament-panels.md`, but the
   match is accidental: the day a supervisor is granted `branches.view_all` for reporting,
   they also get account creation, role assignment and delete. Needs its own permission —
   `users.manage` — which **must be added to `RolePermissionSeeder`**: super_admin carries
   only explicitly assigned permissions (`define_via_gate => false`, no `Gate::before`
   anywhere in `app/`), so an unseeded permission is invisible even to admin.
3. **🔴 Delete is a hard delete, and half the time it fails loudly.** `users` has no
   `deleted_at` column and `User` does not use `SoftDeletes` — against CLAUDE.md's "soft
   deletes on master data". `EditUser.php:18` offers `DeleteAction` and the table a
   `DeleteBulkAction`, unguarded. Two outcomes, both bad. `transactions.created_by_id`,
   `expenses.created_by_id` / `updated_by_id` / `approved_by_id` and
   `cash_sessions.opened_by_id` / `closed_by_id` / `reconciled_by_id` are all **`NO ACTION`**
   (verified against `information_schema`), so deleting anyone who has posted to the ledger
   raises a raw FK violation. Deleting anyone else succeeds and `SET NULL`s some fifty
   attribution columns, erasing who created a booking, took a payment or verified a document
   — which is ADV-03's audit trail. Replace with deactivate; if delete stays, soft-delete it
   and guard it.
4. **🟡 A staff member cannot maintain their own account.** The panel registers `->login()`
   and `->passwordReset()` but **no `->profile()`**, and this resource is gated to
   super_admin/manager. A receptionist cannot change their own locale or password from inside
   the panel, and because `password` is `->hiddenOn('edit')` an admin cannot reset one for
   them either — the only path is the emailed reset link.
5. **🟡 `branch_id` is fillable but absent from the form**, and `branchUsers` has no UI. Every
   user created through this screen therefore has `branch_id = null`, which makes
   `accessibleBranchIds()` fall through to its `report()` branch (`User.php:85`) and return
   zero branches. Both log resources pin on branch, so such a user sees nothing in them.
6. **🟡 Empty `->filters([])`, no default sort, N+1 on the roles badge.**
7. **🟡 Deprecated `->actions([...])`** — panel-wide finding 3 in [`README.md`](README.md).
8. **🔵 The roles Select shows raw `super_admin`.** `lang/en/enums.php` already holds
   `user_role.*` labels; use `UserRole::options()` keyed to role ids.
9. **🔵 Dead schema.** `must_change_password`, `last_login_at`, `last_login_ip` and the three
   `two_factor_*` columns are cast on the model and **never read or written anywhere in
   `app/`** — phase-01 lists "optional 2FA" as delivered. Wire them or drop them; a login
   screen that implies 2FA and does not have it is worse than one that does not.

## Checklist

- [ ] Add `users.manage` to `RolePermissionSeeder` and re-gate `canAccess()` on it
- [ ] Move role assignment out of the form into a guarded action that cannot target the
      acting user's own record, and cover it with a test asserting a manager cannot
      self-assign `super_admin`
- [ ] Replace delete with deactivate; if delete stays, add `SoftDeletes` + a guard for users
      with ledger rows, and drop `DeleteBulkAction`
- [ ] Add `->unique(ignoreRecord: true)` to `phone`
- [ ] Add `branch_id` to the form and a `branchUsers` relation manager on edit
- [ ] Add a reset-password action; decide whether `must_change_password` is wired or dropped
- [ ] Register a `->profile()` page so staff can change their own locale and password
- [ ] Section the form; add the role / `is_active` / branch filters; `defaultSort('name')`
- [ ] Eager-load `roles` and `branch`; `->actions(` → `->recordActions(`
- [ ] Render roles through `UserRole` labels
- [ ] Add a test asserting the form and table expose none of `password`, `remember_token`,
      `two_factor_secret`, `two_factor_recovery_codes` — this is right today and must stay right

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/PanelAccessTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/UserResource.php app/Filament/Admin/Resources/UserResource
```

Neither test currently touches this resource: `ResourcePagesRenderTest` only ever acts as
super_admin, and `PanelAccessTest` asserts each role reaches `/admin` but not what it may then
open. New coverage is required, not just a green run.

By hand: log in as `manager@mcars.dz`, open your own user record, and try to add the
`super_admin` role — today it saves. Then log in as `reception@mcars.dz` and confirm
`/admin/users` is refused. Finally, attempt to delete `accountant@mcars.dz` (who has ledger
rows) and record what the screen does.
