# 36 — AlertRule (Settings)

**Model:** `App\Models\AlertRule` · **Slug:** `/admin/alert-rules` · **Status:** ✅ done

Closes **REQ-17**. Read [`../06-design-decisions.md`](../06-design-decisions.md) ADR-012 and
[`../tasks/phase-08-notifications.md`](../tasks/phase-08-notifications.md) before changing
anything here.

## What it is for

The dials behind every alert: what to watch, how far ahead, how often to repeat, on which
channels, to whom. A manager owns them so that changing an insurance lead time from 30 days to
14 is an afternoon's decision rather than a deploy. Ten rows today — one global rule per
`AlertType`, all active, none branch-scoped, seeded by `AlertRuleSeeder`.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 9 visible columns + 2 toggleable (`recipient_roles`, `template_key`, `updated_by`, `updated_at`), 2 filters (`type`, `is_active`), `defaultSort('type')`, eager-loads `branch` + `updatedBy` |
| create | ✅ | 3 sections (what / when / who), helper text throughout, `type` seeds four fields via `live()`, uniqueness validated in the form (not just the DB) |
| view | ❌ | not needed — see below |
| edit | ✅ | same form; `type` **and `template_key`** `->disabledOn('edit')` |
| row actions | ✅ | `EditAction`, **set active** (deactivate/reactivate), **View deliveries** |
| header / toolbar actions | ✅ | `CreateAction` (index), `DeleteAction` (edit, confirmation names the alert type), `DeleteBulkAction` |
| relation managers | ❌ | none — `notificationLogs` is deliberately elsewhere |
| `canAccess()` | ✅ | `alerts.manage` (`AlertRuleResource.php:66`), seeded to super_admin + manager |

**This is the best-built resource in the Settings group and most of it should be left alone.**
It gates on a permission rather than a role list, uses `recordActions()` / `toolbarActions()`
instead of the deprecated alias (one of only seven resources that do), has filters, has a
default sort, and sections its form with helper text on every non-obvious field. The `channels`
badge column type-hints `fn (string $state)` rather than an enum, which is the *safe* form of
the pattern that once 500'd the bookings list (panel-wide finding 6 in [`README.md`](README.md))
— checked, not assumed.

**"All branches" is legible on the index and half-legible on the form.** `branch_id` is
correctly **not** required, matching `AlertRule::resolveBranchId()` returning null so that
`BelongsToBranch` cannot silently convert a global rule into a one-branch rule. The table renders
the null as `->placeholder(__('…alert_rule.global'))` → "All branches", and the form's helper
text reads "Leave empty to apply to all branches. A branch rule overrides the global one." What
is missing is one line: the Select has no `->placeholder()`, so an empty branch shows Filament's
generic "Select an option", which reads as *unset* rather than as *all branches*. Add
`->placeholder(__('notifications.resources.alert_rule.global'))` so the field states the same
thing the column does.

## Should be

### Index

Add `recipient_roles` as a badge column — who gets told is the point of a rule, and today the
only way to see it is to open the record. Add `template_key` as a toggleable column, hidden by
default, so the dedup key is visible without opening the form. Eager-load `branch`; the table is
ten rows so the N+1 is cheap, but it costs nothing to fix.

Everything else on the index is already right.

### Create

The three sections are correct and the `live()` seeding from `type` is the right pattern —
picking an alert type fills `template_key`, `days_before`, `repeat_every_days` and `max_repeats`
from `AlertType`'s defaults, so a manager starts from a working rule.

What is missing is uniqueness. Two partial unique indexes constrain this table:
`alert_rules_type_branch_active_unique` and `alert_rules_type_global_active_unique` — at most one
*active* rule per `(type, branch)`, and at most one active global rule per type. Since the seeder
already creates a global rule for every `AlertType`, **creating any new global rule through this
form hits the index immediately** and surfaces as a raw `QueryException`. The form must validate
it, scoped to `branch_id` and `is_active = true`; and it is worth asking whether create should
be offered at all, given the realistic operations are "edit the existing rule" and "add a branch
override".

### View

Not needed. A rule is nine fields already laid out in three sections, and the one thing hanging
off it — `notificationLogs` — is an unbounded table that already has its own screen. Per the
Relations rule, that is a report, not a relation manager.

### Edit

`type` is correctly frozen. **`template_key` must freeze too** — see gap 2. Everything else
(lead time, repeats, channels, recipients, active) is exactly what a manager is meant to change.

### Relations

**None, deliberately.** `notificationLogs` (`hasMany`) is the only relation and it must not
become a relation manager here: it grows by several rows per sweep forever, it is read-only
delivery audit, and it is gated on a *different* permission (`alerts.view_logs`, not
`alerts.manage`) — nesting it under this resource would leak recipient addresses to anyone who
can edit a rule.

The right link is a row action: **View deliveries**, navigating to
[`37-notification-log.md`](37-notification-log.md) pre-filtered by `alert_rule_id`, visible only
with `alerts.view_logs`.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| Edit | row | always | `alerts.manage` | — | exists; keep |
| View deliveries | row | always | `alerts.view_logs` | — | navigation only; needs the filter added to NotificationLogResource |
| Deactivate / reactivate | row | on `is_active` | `alerts.manage` | `AlertRule` update | reversible, legible, and frees the partial unique index |
| Delete | edit header | always | `alerts.manage` | — | must state in its confirmation *which* alert stops firing |

## Gaps and risks

All seven gaps below were closed in this round. They are kept as a record of what was wrong
and why, not as open items.

1. **🔴 Phase 10's `BranchScope` hides every global rule the moment the flag flips.**
   `AlertRule` now overrides `withoutBranchScope(): bool { return true; }`, so
   `BelongsToBranch::bootBelongsToBranch()` never attaches the scope — verified by
   `tests/Feature/AlertRuleResourceTest.php` ("never applies the branch scope…") which flips
   `branches.enabled` on, selects a branch, and still sees the global rule.
2. **🔴 `template_key` is the deduplication key and was freely editable.** Now
   `->disabledOn('edit')` alongside `type`; it stays a required free-text field on create,
   where `afterStateUpdated` seeds it from the chosen type. Editing dedup history is closed.
3. **🟡 Creating a rule collided with a partial unique index and showed a raw database error.**
   `UniqueActiveAlertRule` (a `ValidationRule` mirroring both partial unique indexes, scoped to
   `branch_id` and `is_active = true`) turns the collision into a field error on `type`.
4. **🟡 Delete said nothing about what stops.** The edit-page `DeleteAction` confirmation now
   names the alert type ("Deleting this rule permanently stops the « :type » alert…"), and
   deactivate/reactivate is offered as the reversible alternative on the index.
5. **🟡 `recipient_roles` was not visible on the index.** Added as a badge column, plus a
   toggleable `template_key` column (hidden by default) and a toggleable `updated_by.name` +
   `updated_at` pair.
6. **🔵 No audit surfacing.** `updated_by.name` / `updated_at` columns answer "who changed the
   lead time and when" in place.
7. **🔵 `TranslatesModelLabel` was dead here.** Dropped from `AlertRuleResource`,
   `NotificationLogResource` and `ActivityLogResource`.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/AlertRuleResourceTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/ResourcePagesRenderTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/AlertRuleResource.php app/Filament/Admin/Resources/AlertRuleResource app/Rules/UniqueActiveAlertRule.php
```

`ResourcePagesRenderTest` covers this resource's index with a row present, so the `channels` /
`recipient_roles` badge closures fire against a non-empty table.

By hand: as `manager@mcars.dz`, open `/admin/alert-rules` and confirm the Branch column reads
"All branches" on all ten rows. Try to create a second global rule of an existing type — the
form now shows a field error on Alert type instead of a raw database error. Then set
`BRANCHES_ENABLED=true`, select a specific branch in the switcher, and reload the list — all ten
global rules remain visible, and the "View deliveries" row action lands on the delivery log
pre-filtered by the rule.
