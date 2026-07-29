# 34 — Role (Settings)

**Model:** `Spatie\Permission\Models\Role` · **Slug:** `/admin/shield/roles` · **Status:** 🔴 needs work

Third-party: `RoleResource extends BezhanSalleh\FilamentShield\Resources\Roles\RoleResource`.
Audited lightly — the form, table and pages are Shield's. Serves **REQ-20**.

## What it is for

Where a role's permission set is edited. Nobody should need it often: the six roles and their four
permissions are created by `RolePermissionSeeder`, which is the real source of truth. A screen that
can silently overwrite what a seeder owns is the whole finding here — editing a role changes who can
do what, so authorization is the only axis this file is about.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | Shield's table: name, guard, permission count, `updated_at`; `->filters([])` empty |
| create | ✅ | Shield's `CreateRole`; our page class is a bare stub |
| view | ❌ | Shield ships one; our `getPages()` **drops it** — no `shield/roles/{record}` route |
| edit | ✅ | Shield's `EditRole`; bare stub |
| row actions | ✅ | `EditAction` + `DeleteAction`, **inherited** from Shield's `table()` |
| header / toolbar actions | ✅ | `DeleteBulkAction`, inherited |
| relation managers | ❌ | none, and none belongs |
| `canAccess()` | ❌ | absent, and no policy backs it either |

The project overrides exactly two things: `$navigationGroup = 'Settings'` and `getPages()`. Slug,
form, table and labels all come from Shield and `config/filament-shield.php`.

Correction to panel-wide finding 3 in [`README.md`](README.md): this resource **does** have row
actions. Our subclass defines no `table()`, which is why a scan of `app/` reports none.

## Should be

### Index

Shield's, unchanged — the right four columns for six rows. Do not add filters or sorting.

### Create

**Should not be reachable.** The role list is `UserRole`, a six-case enum, and the seeder creates
exactly those. A seventh role has no enum case, so `User::canAccessPanel()`
(`hasAnyRole(UserRole::values())`) would not admit its holders — the row would grant a permission
set nobody can use. Remove the page.

### View

Not needed, and the project already dropped Shield's. A role is a name plus a permission set, both
on edit.

### Edit

The only surface worth keeping, once gap 2 is fixed. `name` must freeze once assigned: it is the
string `UserRole`, `RolePermissionSeeder`, `AlertRule::recipient_roles` and every `assignRole()`
call match on, so renaming `manager` detaches the role from all four without error. `guard_name`
should not be editable — everything here is `web`.

### Relations

**None**, and none should be added. The permission set is not a relation manager: Shield renders it
as checkbox lists inside the form and `EditRole::afterSave()` syncs it from that state. The inverse
— which users hold this role — belongs on [`33-user.md`](33-user.md), where assignment lives.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| Edit | row | always | `roles.manage` | — | inherited; keep, gate it |
| Create | — | remove | — | — | see Create |
| Delete / bulk delete | — | remove | — | — | see gap 3 |

## Gaps and risks

1. **🔴 Every staff role can open it.** No `canAccess()` and no policy: Shield registers a role
   policy only when `app/Policies/RolePolicy.php` exists, and `app/Policies/` does not exist —
   verified live, `Utils::isRolePolicyRegistered()` is `false` and `Gate::getPolicyFor(Role::class)`
   is `null`. Filament's `can()` treats a missing policy as permission granted. Verified by calling
   `canAccess()` as each of the six seeded users: **all six return true**, `reception@mcars.dz`
   included. A receptionist can create roles, edit `super_admin` and delete roles.
2. **🔴 Saving any role strips all four seeded permissions from it.** `EditRole::afterSave()` calls
   `syncPermissions()` with nothing but the form's own state, so a permission the form cannot render
   is a permission the save removes — and none of the four can be rendered. The custom-permissions
   tab is switched **off** (`shield_resource.tabs.custom_permissions => false`), and the 456
   resource/page/widget options were enumerated and contain none of them. Enabling the tab would not
   fix it: `format_custom_permission_keys` with `case => pascal` rewrites the single configured
   entry, and `FilamentShield::getCustomPermissions()` returns `{"BranchesViewAll": …}` — a
   different name from the seeded `branches.view_all`. **Consequence:** open `super_admin`, press
   Save, and money, user administration, branch administration, alert rules and both log resources
   become unreachable for everybody, with no UI path back. It bites this hard because
   `config/filament-shield.php` sets `'super_admin' => ['define_via_gate' => false]` and there is no
   `Gate::before` anywhere in `app/`: the role carries **only** explicitly assigned permissions.
   With gap 1, any receptionist can trigger it.
3. **🟡 Deleting a role locks its holders out of the panel.** `canAccessPanel()` requires
   `hasAnyRole(UserRole::values())`, and Spatie cascades `model_has_roles`. Deleting `super_admin`
   locks out the only account that could restore it.
4. **🟡 The permission list this screen edits is not the list the app enforces.** Only four
   permissions exist — confirmed against the live `permissions` table — while `app/` gates on a
   fifth, `reverse_transaction`, that the seeder never creates (see
   [`13-transaction.md`](13-transaction.md) gap 1). Every new permission this audit recommends
   (`users.manage`, `roles.manage`, `audit.view`) must go into `RolePermissionSeeder` for the same
   reason. Separately, Shield's 456 generated checkboxes are **inert**: with no policies, nothing
   checks a `View:Booking`-style permission, so ticking them changes no behaviour while looking
   authoritative. Adopt policies via `shield:generate`, or set `permissions.generate => false`.

## Checklist

- [ ] Add `canAccess()` on a seeded `roles.manage`, plus a test that a receptionist is refused
- [ ] Stop the save from stripping seeded permissions: enable the custom tab with
      `format_custom_permission_keys => false` **and** list all four in `custom_permissions`, or
      make the resource read-only and leave the seeder as the only writer
- [ ] Remove the create page and both delete actions; freeze `name`; hide `guard_name`
- [ ] Decide on the 456 generated permissions: adopt policies, or turn generation off
- [ ] Add a test asserting each seeded role's permission set still matches the matrix in
      `../02-filament-panels.md` **after** a role is saved through the resource

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/PanelAccessTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/RoleResource.php app/Filament/Admin/Resources/RoleResource
```

By hand, on a database you can re-seed: as `reception@mcars.dz`, open `/admin/shield/roles`, edit
`super_admin`, save without changing anything, then run `select r.name, count(*) from roles r join
role_has_permissions rp on rp.role_id = r.id group by r.name`. `super_admin` holds 4 rows before the
save (9 across all roles) and 0 after. Re-run `RolePermissionSeeder` afterwards.
