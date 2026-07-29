# 36 — AlertRule (Settings)

**Model:** `App\Models\AlertRule` · **Slug:** `/admin/alert-rules` · **Status:** 🟡 partial

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
| index | ✅ | 7 columns, 2 filters (`type`, `is_active`), `defaultSort('type')` |
| create | ✅ | 3 sections (what / when / who), helper text throughout, `type` seeds four fields via `live()` |
| view | ❌ | not needed — see below |
| edit | ✅ | same form; `type` `->disabledOn('edit')` |
| row actions | ✅ | `EditAction`, via `->recordActions([...])` — the correct Filament 5 name |
| header / toolbar actions | ✅ | `CreateAction` (index), `DeleteAction` (edit), `DeleteBulkAction` |
| relation managers | ❌ | none — `notificationLogs` is deliberately elsewhere |
| `canAccess()` | ✅ | `alerts.manage` (`AlertRuleResource.php:65`), seeded to super_admin + manager |

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

1. **🔴 Phase 10's `BranchScope` will hide every global rule the moment the flag flips.**
   CLAUDE.md is explicit: "Phase 10's branch scope must leave `alert_rules` alone." It does not.
   `AlertRule` overrides `resolveBranchId()` but **not** `withoutBranchScope()`, so
   `BelongsToBranch::bootBelongsToBranch()` adds the scope. Verified empirically: with
   `config(['branches.enabled' => true])`, `(new AlertRule)->getGlobalScopes()` returns
   `[BranchScope, SoftDeletingScope]`, while `(new User)->getGlobalScopes()` correctly returns
   `[]`. `BranchScope::apply()` adds `where branch_id = ?`, which excludes every `branch_id IS
   NULL` row — that is all ten rules. A manager who picks a specific branch in the switcher would
   see an empty list and could reasonably conclude no alerts are configured. Detection itself is
   safe *today* only by accident: `AlertRule::resolveFor()` uses `static::query()` and is called
   from `NotificationService.php:315` on the console sweep, where `BranchContext::isResolved()` is
   false and the scope no-ops. `config('branches.enabled')` is currently **false** in this
   environment, so this is latent rather than live — which is exactly when it is cheap to fix.
   Add `withoutBranchScope(): bool { return true; }` to `AlertRule` with the reason in a comment,
   and a test that asserts a global rule is visible with the flag on and a branch selected.
2. **🔴 `template_key` is the deduplication key and is freely editable.** The class docblock and
   the inline comment on `type` both justify freezing `type` because changing it "would orphan
   every notification_log pointing at the old template key, which is what deduplication reads" —
   and then `template_key` is offered as a required free-text `TextInput` on create *and* edit.
   The dedup query keys on `(template_key, related_type, related_id, channel)`
   (`NotificationService.php:200`, covered by `notification_logs_dedup_idx`), so editing that one
   field forgets every subject's alert history and re-alerts everyone at the next sweep. Freezing
   `type` while leaving `template_key` open protects nothing. Either `->disabledOn('edit')` it
   too, or make it non-editable and derived — `AlertType::defaultTemplateKey()` already supplies
   it and `afterStateUpdated` already sets it. ADR-012 calls deduplication correctness, not
   optimisation; this is the field that breaks it.
3. **🟡 Creating a rule collides with a partial unique index and shows a raw database error.**
   See Create.
4. **🟡 Delete says nothing about what stops.** `AlertRule` soft-deletes and the unique indexes
   exclude soft-deleted rows, so a delete is recoverable and does not block re-creating the rule
   — that part is by design (see the migration comment). But deleting the global rule for, say,
   insurance expiry silently ends that alert for every branch. The confirmation must name the
   alert type, and deactivate should be the offered default.
5. **🟡 `recipient_roles` is not visible on the index.** See Index.
6. **🔵 No audit surfacing.** `alert_rules` carries `created_by_id` / `updated_by_id` via
   `HasAuditColumns` and the model uses `LogsActivity`, but neither is shown anywhere on this
   screen. After an incident the first question is who changed the lead time and when; today it
   is answered from [`38-activity-log.md`](38-activity-log.md). A `updated_by.name` +
   `updated_at` pair on the index would answer it in place.
7. **🔵 `TranslatesModelLabel` is dead here.** The trait is imported and used, but the class also
   declares `getModelLabel()` and `getPluralModelLabel()`, and a class method wins over a trait
   method. Same in `NotificationLogResource` and `ActivityLogResource`. Harmless; drop the trait
   from those three for clarity.

## Checklist

- [ ] Add `withoutBranchScope(): bool { return true; }` to `AlertRule`, with a test asserting a
      global rule stays visible with `branches.enabled` on and a branch selected
- [ ] Freeze `template_key` on edit (or derive it from `type` and make it read-only)
- [ ] Validate uniqueness of `(type, branch_id)` among active rules on the create form
- [ ] Add `->placeholder(__('notifications.resources.alert_rule.global'))` to the branch Select
- [ ] Add `recipient_roles` badge column and a toggleable `template_key` column
- [ ] Add a deactivate/reactivate row action; make the delete confirmation name the alert type
- [ ] Add a "View deliveries" row action gated on `alerts.view_logs`
- [ ] Eager-load `branch`; consider showing `updated_by.name` / `updated_at`
- [ ] Drop the unused `TranslatesModelLabel` trait

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/AlertRuleResource.php app/Filament/Admin/Resources/AlertRuleResource
```

`ResourcePagesRenderTest` does **not** cover this resource — it needs adding there too, with a
row present, since the `channels` badge closure only fires against a non-empty table.

By hand: as `manager@mcars.dz`, open `/admin/alert-rules` and confirm the Branch column reads
"All branches" on all ten rows. Try to create a second global rule of an existing type and record
what the screen does. Then set `BRANCHES_ENABLED=true`, select a specific branch in the switcher,
and reload the list — today it is empty.
