# Phase 1 — Auth, Roles & Permissions, Panel Skeletons

**Status: ✅ Done** · Depends on: Phase 0 · Closes: **REQ-20**, **ADV-06** (schema only)

Three panels exist, users land in the right one, permissions are enforced.

## Read first
[`../02-filament-panels.md`](../02-filament-panels.md) (role matrix, isolation layers) ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-004, ADR-007 ·
[`../08-multi-branch-retrofit.md`](../08-multi-branch-retrofit.md) §1 (branch access resolution)

> **Stack note:** this is **Filament 5**, while `docs/02` was written against v4. Verify API names
> (panels, clusters, resources) against v5 as you go, and correct `docs/02` in this session.

## Already done in Phase 0
`branches` table · `Branch` model · `BranchFactory` · `BranchSeeder` (Main Branch / `MAIN` /
`is_default`, with a partial unique index) · `BelongsToBranch` trait.

## Deliverables

- [x] **Users table extension** — `branch_id` (nullable FK, **stays nullable forever**: null = global
      access), `phone`, `whatsapp`, `avatar`, `locale`, `is_active`, `last_login_at`, `last_login_ip`,
      `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`,
      `must_change_password`.
- [x] **`branch_user` pivot** — `user_id`, `branch_id`, `is_primary`, unique on the pair.
- [x] **`User::accessibleBranchIds()`** implementing exactly this resolution:

      | Condition | Access |
      |---|---|
      | has permission `branches.view_all` | **all branches** — pivot ignored |
      | pivot has rows | exactly those |
      | pivot empty, `users.branch_id` set | that one |
      | pivot empty, `branch_id` null, no global permission | **none — deny, and log it** |

      The last row is the point: an unconfigured staff account must see *nothing*, not *everything*.
- [x] **Spatie Permission + Filament Shield** installed, `shield:generate` wired into the seeder.
- [x] **Roles seeded** — `super_admin`, `manager`, `accountant`, `receptionist`,
      `maintenance_officer`, `supervisor`, `car_owner`, `client`. Permission sets per the matrix in
      [`../02-filament-panels.md`](../02-filament-panels.md).
- [x] **Three PanelProviders** — `admin` (`/admin`), `owner` (`/owner`), `client` (`/client`). Own
      branding, own middleware stack, empty navigation. One `users` table, **not three guards**
      (ADR-007).
- [x] **`User::canAccessPanel(Panel $panel)`** gating by role.
- [x] **`EnsureUserIsCarOwner` / `EnsureUserIsClient`** middleware on the portal panels.
- [x] **`UserResource`, `RoleResource`, `BranchResource`** in the admin panel; `BranchResource`
      restricted to `manager` / `super_admin`.
- [x] **Auth flows** — login, password reset, optional 2FA, rate limiting on login, reset and OTP.
- [x] **`branch_id` (nullable) on every table created from this phase onward.** Enforcement stays off
      (`config('mcars.branches.enabled')` is `false`) until Phase 10 (ADR-004).
- [x] Apply `BelongsToBranch` + `HasAuditColumns` to `User`, then **delete the `trait.unused` entry
      from `phpstan.neon`** — it will fail the build once matched.
- [x] Role/permission labels added to `lang/{ar,fr,en}/enums.php`.

## Tests

- [x] A `client` user cannot reach `/admin` → 403
- [x] A `receptionist` cannot reach `/owner` → 403
- [x] Each seeded role's permission set matches the matrix in `../02-filament-panels.md`
- [x] A user with no branch and no global permission resolves to **zero** accessible branches
- [x] A user with `branches.view_all` resolves to all branches regardless of pivot rows
- [x] Login is rate limited

## Definition of done

Log in as each of the eight roles; each lands on the correct panel and sees nothing beyond it.
Pint, PHPStan and Pest all green.

## Watch out

- **Do not scope `users` by branch.** An admin can lock themselves out of user management.
- Hidden navigation is not security — enforce with Shield permissions, not `->hidden()`.
- Portal panels get **no** internal resources registered. An unregistered resource has no route, which
  is the strongest isolation layer available (`../02-filament-panels.md` layer 1).
