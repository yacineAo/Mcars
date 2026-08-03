# 33 — User (Settings)

**Model:** `App\Models\User` · **Slug:** `/admin/users` · **Status:** ✅ done

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
| index | ✅ | name/email searchable, roles badges, `branch.name`, `is_active` icon, `last_login_at`; role + `is_active` (default active) + branch filters; `defaultSort('name')`; eager-loaded `roles` + `branch` |
| create | ✅ | sectioned: Identity / Access / Placement / Preferences; no roles field; `phone` unique-validated, nullables unconstrained |
| view | ❌ | deliberately — see "Decisions taken", §view |
| edit | ✅ | sectioned; `password` and secrets absent; header actions assign-roles / reset-password / deactivate (each `->record($record)`), no delete |
| row actions | ✅ | edit, assign roles, reset password, set active — hidden on the acting user's own record where that matters |
| header / toolbar actions | ✅ | `CreateAction`; **no bulk actions** |
| relation managers | ✅ | `branchUsers` on edit: attach with `is_primary` pivot, pivot edit, detach |
| `canAccess()` | ✅ | on `users.manage` (super_admin, manager) — not `branches.view_all` |

## Decisions taken (Round 33)

1. **`users.manage` gates the resource; roles are assigned by action, not form field.**
   The old unfiltered in-form `roles` Select was a self-promotion vector — a manager could
   tick `super_admin` on their own record (gap 1). The form has no roles field at all.
   `assign_roles` is a row/header action whose `CheckboxList` options are
   `UserService::assignableRoleNames()` — *never* the actor's own record, and the
   options list is validated server-side by the form (`In` rule over the actor's
   assignable roles), so a posted `super_admin` from a manager is rejected at the form
   boundary, not silently. `UserService` re-checks everything for defence in depth and
   writes `roles_updated` activity with the actor.
2. **Delete is replaced by deactivate.** A hard delete either violated ledger foreign
   keys (`transactions.created_by_id` etc. are `NO ACTION`) or `SET NULL`ed the
   attribution columns that *are* ADV-03's audit trail. `set_active` parks the account:
   `is_active` gates `canAccessPanel()` on every request, so a leaver is out on their
   very next request. No delete, no `DeleteBulkAction`, no `SoftDeletes` — parking
   needs no tombstone.
3. **Password reset is an action with a confirmation step** (`Password::default()`,
   `->same('password_confirmation')`), and it flips the previously-dead
   `must_change_password` column. `ForcePasswordChange` middleware (panel auth stack,
   after `Authenticate`, exempting logout and the profile page) redirects every other
   request to the profile until the user saves a new password there; `EditProfile`'s
   `afterSave()` clears the flag.
4. **Gap 4 closed — staff can maintain their own account.** `->profile(EditProfile::class)`
   registers a profile page (name, phone, whatsapp, locale, password block — no email,
   no roles). `UserResource` stays gated to `users.manage`; a receptionist changes their
   own locale/password on the profile, not in the resource.
5. **2FA is now real, not implied.** The panel registers
   `->multiFactorAuthentication([AppAuthentication::make()])`; the previously-dead
   `two_factor_secret` / `two_factor_recovery_codes` columns are wired through
   `HasAppAuthentication` / `HasAppAuthenticationRecovery` and added to
   `#[Fillable]` (they are only ever written by the interface methods). The genuinely
   dead `two_factor_confirmed_at` column is dropped in migration
   `2026_08_17_000000_drop_two_factor_confirmed_at_from_users_table` — Filament never
   reads it, so it could only lie.
6. **`last_login_at` / `last_login_ip` are wired.** `RecordLastLogin` listens on
   `Login::authenticate` and `forceFill()`s both (last login is written through the
   security boundary, not the fillable surface) and shows in the index.
7. **Branch placement is no longer tinker-only.** `branch_id` is a `Select` on the form
   (so new users land with an actual branch instead of the `report()` fallback), and the
   `branchUsers` relation manager maintains the pivot on the edit page — attach with an
   `is_primary` toggle, pivot edit, detach. The RM's `AttachAction` composes
   `$action->getRecordSelect()` with the toggle (`->schema()` alone would replace the
   record select and break attach).
8. **View page: not built, on purpose.** A user is a handful of fields; the question
   "what has this person done" is answered by the ActivityLog resource filtered by
   causer. A read-only activity slice may come later without breaking anything.
9. **The audit trail stays honest.** The forms and table expose none of `password`,
   `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, and the
   `LogsActivity` exclusion keeps hashes out of `activity_log` — both pinned by tests.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/UserResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/PrivilegeEscalationTest.php
```

`UserResourceTest` (17 tests) covers create/duplicate-phone/no-phone, secrets out of
forms and table, the deactivate/reactivate cycle plus next-request 403, self-deactivate
refusal, reset-password + forced-change redirect + flag clearing, own-locale editing,
last-login recording, role/password-reset audit with the actor, no-delete-anywhere,
branch attach/pivot-edit/detach, and page rendering. `PrivilegeEscalationTest` pins the
privilege paths: `users.manage` gating, manager role-option constraints, form-level
rejection of `super_admin`, hidden own-record actions, and the service-level guards
(own-role change, manager → super_admin account).

By hand: log in as `manager@mcars.dz` — `/admin/users` opens, `assign roles` never
offers `super_admin`, and your own row has no assign/deactivate actions. Log in as
`reception@mcars.dz` — `/admin/users` is refused (403) and the profile page lets you
change your own locale and password. Deactivate `accountant@mcars.dz` and confirm the
very next request bounces them with a 403.
