# 37 — NotificationLog (Settings)

**Model:** `App\Models\NotificationLog` · **Slug:** `/admin/notification-logs` · **Status:** 🟡 partial

Serves **REQ-17**. Read [`../06-design-decisions.md`](../06-design-decisions.md) ADR-012 and
[`../tasks/phase-08-notifications.md`](../tasks/phase-08-notifications.md) before changing
anything here.

## What it is for

The delivery audit trail: one row per attempt, per channel, per recipient. A manager opens it to
answer two questions — "was this person actually told?" and "why did that one fail?" It is also
the deduplication source of truth, which is why it is read-only: the ADR-012 window is computed
from these rows, so a deleted or edited row silently reopens the window for a subject that was in
fact already alerted.

## Current state

| Surface | Exists | Notes |
|---|---|---|
| index | ✅ | 8 columns, 3 filters, `defaultSort('created_at','desc')`, eager-loads `alertRule`, `user`, `branch` |
| create | ❌ | `canCreate()` returns false — correct |
| view | ✅ | Delivery section (12 entries) + Content section (payload + error) |
| edit | ❌ | `canEdit()` returns false — correct |
| row actions | ✅ | `ViewAction` only, via `->recordActions([...])` |
| header / toolbar actions | ❌ | none — correct |
| relation managers | ❌ | none, and none belongs |
| `canAccess()` | ✅ | `alerts.view_logs` (`NotificationLogResource.php:59`) |

**The read-only contract holds, and it was checked rather than assumed.** `canCreate()`,
`canEdit()` and `canDelete()` all return false; `getPages()` registers only `index` and `view`;
`ViewAction` is the only action anywhere on the resource; and there is **no resend or retry** —
`app/Filament/` was grepped for both, and the only hits are `PaymentResource` (posting retry) and
`ReportResource` (export retry), neither of which touches this table. So nothing on this screen
can bypass `MessagingService` and re-open the dedup window. The model also correctly omits
`SoftDeletes`, and its docblock says why.

**The payload renders safely.** `KeyValueEntry::toEmbeddedHtml()` passes every key and value
through `e()`, and `json_encode`s non-scalar values before escaping (read from
`vendor/filament/infolists/src/Components/KeyValueEntry.php`). A stored message body cannot inject
markup here. No change needed — and no `->formatStateUsing()` returning `HtmlString` should ever be
added to that entry.

Branch pinning is enforced server-side in `getEloquentQuery()`, not by a submitted filter, which is
what CLAUDE.md requires. See gap 1 for the one thing wrong with it.

## Should be

### Index

Keep the column set and add what is missing:

- **`user.name`, with `recipient` as the fallback.** See gap 2 — the recipient column currently
  shows a bare user id for in-app deliveries.
- A link on the subject (`related_type` / `related_id`) rather than a bare
  `class_basename()`. The subject is why the row exists; `related()` is already a `MorphTo`.
- `cost`, once a paid driver ships (see gap 6).

Filters, in addition to the three that exist (`status`, `channel`, failed-only):

- **`created_at` range** — the first thing anyone reaches for on an append-only table.
- `SelectFilter` on `alertRule.type`.
- A filter on `alert_rule_id`, so the "View deliveries" row action proposed in
  [`36-alert-rule.md`](36-alert-rule.md) has something to link to.
- Branch filter, visible only with `branches.view_all`.

### Create

Must never exist. Rows are written by `NotificationService` at the moment the decision to notify
is taken — before the queue worker touches the message — because that write *is* the dedup claim.

### View

Keep; it is the reason the resource exists. The Delivery section answers the timeline question well
(queued / sent / delivered / failed as four separate timestamps, plus `attempts`, `provider` and
`provider_message_id`). Two additions: link the subject, and link `alertRule` back to
[`36-alert-rule.md`](36-alert-rule.md) so "which rule produced this" is one click. Show
`user.name` beside `recipient`.

### Edit

Must never exist (ADR-012). A correction to a delivery record is not a concept — the row records
what happened, and what happened does not change.

### Relations

**None.** A delivery row is a leaf: `alert_rule_id`, `user_id`, `branch_id` and the
`related_type`/`related_id` morph all point *outward* and belong on the view page as links.

The reverse direction is where the temptation lies — a booking's deliveries on *its* view page.
That is a filtered view of this table, and if it is ever added it must be strictly read-only and
gated on `alerts.view_logs`, not on whatever gates the subject; nesting it under a weaker gate
would leak recipient addresses and message bodies.

### Actions

| Action | Placement | Visible when | Guarded by | Delegates to | Notes |
|---|---|---|---|---|---|
| View | row | always | `alerts.view_logs` | — | exists; keep |
| Prune old deliveries | — | console only | — | a scheduled command | see gap 3; must never be a UI delete |

No state-changing action belongs on this screen at all. That is the design, not an omission.

## Gaps and risks

1. **✅ Branch pinning now matches `ActivityLogResource`.** `getEloquentQuery()` resolves
   `User::accessibleBranchIds()` — `branch_user` pivot first, then home `branch_id`, then
   `whereRaw('1 = 0')` for a user with neither — so there is one rule and one place to get it
   wrong. Verified by a test covering the pivot case and the deny case.
2. **✅ `recipient` shows the human, not a user id.** The index column displays `user.name` and
   falls back to `recipient` for external addresses; both columns stay searchable.
3. **✅ The table can be narrowed by date, and has a retention policy.** `created_at` range filter
   added, and `alerts:prune-logs` (scheduled daily at 05:30, after `activitylog:clean`) deletes
   terminal rows older than 365 days — never Queued/Sending, whose dedup window is still open.
   ADR-012 only needs history as far back as the longest `repeat_every_days` window, so a
   scheduled prune is compatible with correctness where a UI delete never is.
4. **✅ Coupling to `reports.view_financials` documented.** `RolePermissionSeeder` now explains
   that `notification_logs.payload` embeds amount-bearing keys and that the two permission sets
   must stay aligned. Chosen over redaction because the payload is the point of the audit trail.
5. **✅ `LogsActivity` dropped from `NotificationLog`.** The delivery log already is the audit
   trail for deliveries; duplicating transitions into ADV-03's trail inflated the busiest audit
   table for information nobody looked for there. Verified by a test that a full send/fail cycle
   writes zero `activity_log` rows.
6. **🔵 `cost` is invisible.** `notification_logs.cost` is `decimal(18,2)`, cast `decimal:2`,
   defaulted to 0, documented in the migration as per-message provider cost in DZD — and it appears
   on no column and no infolist entry. Harmless while every driver is free. Recording it now: when
   "what did alerting cost last month" is asked, it is an aggregation, so it goes through
   `ReportService`, never summed on this table.
7. **✅ The subject is a link.** `related_type` still renders through `class_basename()`, but the
   column and infolist entry now link to the subject's view page (via
   `NotificationLogResource::subjectUrl()`, which checks a resource with a view page actually
   exists) and the alert rule links back to its edit page.
8. **✅ `TranslatesModelLabel` dropped** — the class declares its own `getModelLabel()` and
   `getPluralModelLabel()`.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/NotificationLogResourceTest.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/NotificationLogResource.php app/Filament/Admin/Resources/NotificationLogResource
```

`ResourcePagesRenderTest` now seeds a `NotificationLog` row, so the index and view pages — including
the payload entry — render against real data.

By hand: as `manager@mcars.dz`, open a delivery and confirm the payload table renders as escaped
text — paste `<b>x</b>` into a payload value in tinker first and confirm it appears literally, not
as bold. Then open the same page as `accountant@mcars.dz` and confirm the whole resource is
refused, not merely the payload.
