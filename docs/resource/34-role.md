# 34 — Role (Settings)

**Model:** `Spatie\Permission\Models\Role` · **Slug:** `/admin/shield/roles` · **Status:** ✅ done

Third-party: `RoleResource extends BezhanSalleh\FilamentShield\Resources\Roles\RoleResource`.
Serves **REQ-20**. The audit found the role screen wide open — see the gap log below — and
Round 34 closed all four.

## What it is for

Where a role's permission set is edited. Nobody should need it often: the six roles and their
permissions are created by `RolePermissionSeeder`, which is the real source of truth. A screen that
can silently overwrite what a seeder owns is the whole finding here — editing a role changes who can
do what, so authorization is the only axis this file is about.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | our `table()`: name, guard, permission count, `updated_at`; no filters; `recordActions([EditAction])` only |
| create | ❌ | **removed** — the role list is the `UserRole` enum; a seventh role could never log in |
| view | ❌ | deliberately — a role is a name plus a permission set, both on edit |
| edit | ✅ | our form: `name` **disabled** (frozen once assigned), `guard_name` **hidden** (everything here is `web`), select-all toggle, custom-permissions tab only |
| row actions | ✅ | edit only — **no delete** |
| header / toolbar actions | ✅ | **none** — no create, no bulk actions |
| relation managers | ❌ | none, and none belongs |
| `canAccess()` | ✅ | on `roles.manage` (super_admin, manager) — same spread as `users.manage`, see below |

## Decisions taken (Round 34)

1. **`roles.manage` gates the resource, seeded alongside `users.manage`.**
   `RolePermissionSeeder::grants()` is the single source of truth — one map of
   `permission => list<UserRole>`; `run()` iterates it and `permissionNames()` derives the list.
   `roles.manage` is granted to exactly the same two roles as `users.manage`, so the pair of
   access-control screens can never drift apart. The permission test lives in
   `tests/Feature/RoleResourceTest.php`: receptionist → `canAccess()` false + HTTP 403,
   manager → 200.
2. **Only the enforced permissions render — a save can no longer strip the matrix.**
   Round 34's central regression: with the custom-permissions tab off and `format_custom_permission_keys`
   rewriting the configured entry, `EditRole::afterSave()` synced every role to an empty set (gap 2).
   `config/filament-shield.php` now renders the custom tab only (pages/widgets/resources tabs off,
   `format_custom_permission_keys => false`, `permissions.generate => false` — the 456 inert
   `View:Car`-style options no longer exist), and `custom_permissions` lists the 23 permissions the
   app actually enforces — the seeder's `permissionNames()`. The parity test asserts
   `config(...) toEqualCanonicalizing(RolePermissionSeeder::permissionNames())`, so a permission
   added to the seeder without a config entry (or vice versa) fails the build before it can strip
   a role. A no-op save on each of the six roles preserves its exact permission set (tested).
3. **No create, no delete — the seeder is the only writer of the role list.**
   A seventh role has no `UserRole` case, so `canAccessPanel()` would never admit its holders;
   deleting `super_admin` locks out the only account that could restore anything (gap 3). The
   create page and route are gone, `ListRoles` and `EditRole` return no header actions, and the
   table carries a single `EditAction` row action.
4. **`name` freezes once assigned; `guard_name` hides.** Renaming `manager` would silently detach
   `assignRole()`, `AlertRule::recipient_roles` and the seeder from the role — the field is
   `disabled` (and not dehydrated, so a crafted save cannot rename it either). Everything in the
   panel runs on the `web` guard, so the guard field is hidden rather than editable.
5. **The pages rebind to the app resource.** Shield's `ListRoles`/`EditRole` hardcode
   `protected static string $resource = \BezanSalleh\FilamentShield\...\RoleResource::class`.
   Without a `$resource` override in the app page classes, Filament resolved Shield's *own*
   resource — `getPages()`/`getTable()`/`getForm()` overrides on the app `RoleResource` were
   dead code, the breadcrumb referenced Shield's dropped `view` route (crash on edit), the
   inherited delete actions reappeared, and the receptionist gate never ran. Both app pages set
   `$resource = App\Filament\Admin\Resources\RoleResource::class`.

## Gaps and risks (resolved)

1. 🔴 **Every staff role could open it** — no `canAccess()`, no policy, and Filament treats a
   missing policy as permission granted: all six seeded users, `reception@mcars.dz` included,
   could edit `super_admin`'s permission set. → Resolved by decision 1; the route now 403s.
2. 🔴 **Saving any role stripped all seeded permissions** — the form rendered 456 inert Shield
   checkboxes containing none of the four real permissions, so `syncPermissions()` emptied the
   set; with no `Gate::before`, the panel lost money, user administration, branch administration,
   alert rules and both log resources with no UI path back. → Resolved by decision 2; a save
   preserves the set, and the parity test keeps the rendered list honest.
3. 🟡 **Deleting a role locked its holders out of the panel.** → Resolved by decision 3.
4. 🟡 **The screen's permission list was not the list the app enforces.** → Resolved by decision
   2: the list *is* `RolePermissionSeeder::permissionNames()`. New permissions must be added to
   `grants()` *and* `config/filament-shield.php`'s `custom_permissions` — the parity test is the
   reminder.

## Checklist

- [x] Add `canAccess()` on a seeded `roles.manage`, plus a test that a receptionist is refused
- [x] Stop the save from stripping seeded permissions: custom tab on, `format_custom_permission_keys
      => false`, `custom_permissions` == seeder list, generation off
- [x] Remove the create page and both delete paths; freeze `name`; hide `guard_name`
- [x] Decide on the 456 generated permissions: generation off — no policies exist to honour them
- [x] Add a test asserting each seeded role's permission set still matches the matrix **after** a
      save through the resource (also the seeder ↔ config parity test)

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/RoleResourceTest.php
```

Full suite: 751 passed (10 new, 45 assertions). `phpstan analyse` back at the 361 baseline (the
`grants()` static calls were switched to `self::` for it). Pint clean.
