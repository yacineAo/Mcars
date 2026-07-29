# 38 — ActivityLog (Settings)

**Model:** `App\Models\Activity` · **Slug:** `/admin/activity-logs` · **Status:** 🔴 needs work

Closes **ADV-03** (audit log for every edit/delete). See
[`../tasks/phase-10-portals-audit-backups.md`](../tasks/phase-10-portals-audit-backups.md).
`App\Models\Activity` extends `Spatie\Activitylog\Models\Activity`, and
`config('activitylog.activity_model')` points at the subclass, so the `branch()` relation this
resource uses is live.

## What it is for

The record of who changed what. A manager opens it after something is wrong — a rate that moved, a
booking that was cancelled, a user who gained a role — to answer "who did this, when, and from what
to what". It is the one screen whose value is entirely in its *history*, so its correctness is
measured by whether the before/after values are there and legible.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 7 columns, 3 filters, `defaultSort('created_at','desc')`, eager-loads `causer`, `branch` |
| create | ❌ | `canCreate()` returns false — correct |
| view | ✅ | Details (8 entries) + Changes (`attribute_changes` as `KeyValueEntry`) |
| edit | ❌ | `canEdit()` returns false — correct |
| row actions | ✅ | `ViewAction` only, via `->recordActions([...])` |
| header / toolbar actions | ❌ | none — correct |
| relation managers | ❌ | none, and none belongs here |
| `canAccess()` | 🟡 | present, but on `alerts.view_logs` (`ActivityLogResource.php:51`) — see gap 3 |

**There is no write surface, and that was checked field by field.** `canCreate()`, `canEdit()` and
`canDelete()` all return false; `getPages()` registers only `index` and `view`; `ViewAction` is the
only action on the resource; there are no bulk actions and no `toolbarActions()` at all. The branch
restriction is applied in `getEloquentQuery()` — server-side, not as a submitted filter — and
denies explicitly with `whereRaw('1 = 0')` when a user resolves to no branches, which is the
careful version of the same code in [`37-notification-log.md`](37-notification-log.md) gap 1.
PHPStan is clean.

That is the whole of the good news. The two findings below are the reason the status is red.

## Should be

### Index

Keep the seven columns; add a link on the subject so a row says *which* booking, not just
"Booking". Add the two filters ADV-03 needs and does not have — causer and subject type (gap 4) —
and give `description` a tooltip; at `->limit(60)` the one column that explains the row is the one
that gets cut.

### Create

Must never exist. Rows are written by Spatie's observer through
`App\Models\Concerns\LogsActivity`.

### View

Keep; it is the only place before/after values appear, and phase-10's definition of done is
literally "show an audit trail entry with before/after values". The Details section is right. The
Changes section needs rebuilding as a per-field old → new table — see gap 5.

### Edit

Must never exist. Note the difference from `transactions`: that table has a database trigger
enforcing append-only, this one does not, so the UI's refusal is the only guard — an argument for a
test that asserts it.

### Relations

**None on this resource.** An activity row is a leaf pointing outward at a causer and a subject.

The relation that matters points the other way and is missing everywhere: **a record's own
history**, read-only, on the subject's view page. That is where ADV-03 pays off — a manager opens
*this booking* and sees who changed it — and it is part of the panel-wide relation-manager gap
(finding 4 in [`README.md`](README.md)). The cheap first version is a "History" row action
deep-linking here with `subject_type` and `subject_id` pre-filtered; the full version is a
read-only relation manager gated on the same permission as this resource.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| View | row | always | `audit.view` | — | exists; keep, re-gate |
| Prune | — | console only | — | a scheduled command | retention is policy, never a UI delete |

No state-changing action belongs here.

## Gaps and risks

1. **🔴 The audit trail stores password hashes and this screen displays them.** Verified directly
   against the live database: `activity_log.attribute_changes` on `App\Models\User` `created` rows
   contains the complete attribute set, including
   `"password": "$2y$12$HLJAxtUp6GghLvOV9lxQSuE4psLvzEIfBMSgAw4bYqqIh4IHkv0bW"` and
   `remember_token`. The cause is `App\Models\Concerns\LogsActivity`, which calls
   `LogOptions::defaults()->logAll()` — every attribute of every model using the trait. The
   `#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]`
   attribute on `User` governs **serialization** (`toArray` / `toJson`); Spatie reads the model's
   attributes directly, so it does not cover this. `ActivityLogResource` then renders
   `attribute_changes` in a `KeyValueEntry` on the view page, gated only on `alerts.view_logs`
   (super_admin + manager). A manager can therefore read every user's bcrypt hash, including
   super_admin's, out of the UI and attack it offline. `two_factor_secret` and
   `two_factor_recovery_codes` sit in the same payload and are null today only because 2FA is not
   implemented (see [`33-user.md`](33-user.md) gap 9) — they would be populated the day it ships,
   and a recovery-code list in an audit log defeats 2FA entirely.
   Three things are needed, and none of them is optional: add `->logExcept([...])` for the
   sensitive columns (per-model via `User::getActivitylogOptions()`, or as a shared deny-list in
   the concern so a future model cannot forget); redact at render time as a second layer; and scrub
   the rows already written. Unlike `transactions`, this table has no append-only trigger and no
   accounting meaning, so a one-off scrub command is legitimate here.
2. **🔴 `activity_log.branch_id` is never populated, so the branch restriction denies
   everything.** Verified: **1,227 of 1,227 rows have `branch_id = NULL`**. Migration
   `2026_08_05_000000` added the column and a `(branch_id, created_at)` index; nothing writes it —
   `Activity` does not use `BelongsToBranch`, and there is no observer, no `activity()->tap()` and
   no assignment anywhere in `app/`. `getEloquentQuery()` filters
   `whereIn('branch_id', $accessibleIds)` for a user without `branches.view_all`, which matches
   zero rows, so such a user sees an **empty** audit log rather than a branch-scoped one. The
   `branch.name` column and its `—` placeholder are likewise permanently empty. It has gone
   unnoticed because the only two roles that can open the resource both hold `branches.view_all`,
   which means the bug fires the first time anyone else is granted access. Either stamp `branch_id`
   when the activity is written, or delete the column, the index, the filter path and the column —
   phase-10 lists this as delivered.
3. **🟡 Gated on `alerts.view_logs`, which is about alert delivery.** The role set it resolves to
   (super_admin + manager) matches the Settings & Access row of the matrix in
   [`../02-filament-panels.md`](../02-filament-panels.md), but the match is accidental: widening
   `alerts.view_logs` so a supervisor can triage failed alerts would hand them every recorded
   change in the business, user records included. Needs its own permission, `audit.view`, which
   **must be added to `RolePermissionSeeder`** — super_admin has `define_via_gate => false` and
   there is no `Gate::before` anywhere in `app/`, so an unseeded permission is invisible even to
   admin.
4. **🟡 Phase 10 promised "filterable by user / model / date" and only date is there.** The three
   filters are `log_name`, `event` and a `created_at` range. There is **no causer filter and no
   `subject_type` filter**, so the two questions an audit log exists to answer — "what did this
   employee do" (REQ-15) and "who has touched this record" — cannot be asked. Add a `SelectFilter`
   on `causer_id` against users and one on `subject_type`.
5. **🟡 `attribute_changes` renders as two JSON blobs rather than a diff.** The column is cast
   `collection` and holds `{"attributes": {…}, "old": {…}}`, so `KeyValueEntry` emits exactly two
   rows whose values are `json_encode`d — escaped and safe, but unreadable, and it buries the
   before/after values phase-10's definition of done asks for. Build a per-field old → new table
   from the two keys instead. Doing so also makes gap 1 visible at a glance, which is a reason to
   fix gap 1 first.
6. **🟡 The filter options run two unbounded `distinct()` scans per page render.** Both
   `SelectFilter::make('log_name')` and `SelectFilter::make('event')` call
   `Activity::query()->whereNotNull(…)->distinct()->pluck(…)` in an uncached closure. Trivial at
   1,227 rows; two full scans of the largest audit table on a year-old install. Both sets are
   effectively fixed — hardcode or cache them.
7. **🟡 No route from a record to its own history.** See Relations.
8. **🔵 `description` truncated at 60 characters with no tooltip** — the same defect as
   [`13-transaction.md`](13-transaction.md) gap 6.
9. **🔵 `TranslatesModelLabel` is dead here** — the class declares its own `getModelLabel()` and
   `getPluralModelLabel()`. See [`36-alert-rule.md`](36-alert-rule.md) gap 7.

## Checklist

- [ ] Stop logging `password`, `remember_token`, `two_factor_secret` and
      `two_factor_recovery_codes` via `->logExcept([...])`, and add a test asserting a user
      `created` activity row contains none of them
- [ ] Redact those keys at render time as a second layer, and scrub the rows already written
- [ ] Decide `branch_id`: stamp it when activity is written, or remove the column, index, filter
      and display — and add a test that a user without `branches.view_all` sees their own branch's
      rows rather than none
- [ ] Add `audit.view` to `RolePermissionSeeder` and re-gate `canAccess()` on it
- [ ] Add causer and `subject_type` filters
- [ ] Render `attribute_changes` as a per-field old → new table
- [ ] Hardcode or cache the `log_name` / `event` filter options
- [ ] Link the subject; add a tooltip to `description`
- [ ] Add a "History" row action (or read-only relation manager) on the resources whose records
      matter most: Booking, Car, Customer, User
- [ ] Add a test asserting this resource exposes no create, edit, delete or bulk action
- [ ] Drop the unused `TranslatesModelLabel` trait

## Verification

```bash
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/ActivityLogResource.php app/Filament/Admin/Resources/ActivityLogResource
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
```

`ResourcePagesRenderTest` does not currently cover this resource and only ever acts as super_admin;
both need fixing before the branch behaviour in gap 2 can be asserted.

Confirm gap 1 in one command, before and after the fix:

```bash
docker compose exec pgsql psql -U mcars -d mcars -c \
  "select count(*) from activity_log where subject_type like '%User' and attribute_changes::text like '%password%';"
```

By hand: as `manager@mcars.dz`, open `/admin/activity-logs`, filter to a `User` subject, open the
row, and read the Changes section — the bcrypt hash is currently on screen. Then confirm
`accountant@mcars.dz` is refused the whole resource.
