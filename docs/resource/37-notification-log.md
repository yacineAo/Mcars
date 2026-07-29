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

1. **🟡 Branch pinning uses a different rule here than in `ActivityLogResource`, and ignores the
   pivot.** This resource filters `where('branch_id', $user->branch_id)` — the user's home branch
   only — while `ActivityLogResource` uses `User::accessibleBranchIds()`, which is the resolution
   phase-01 actually specifies (`branch_user` pivot first, then `branch_id`, then deny). A user
   assigned to Alger and Oran through the pivot sees only their home branch's deliveries. And
   Laravel rewrites `where('branch_id', null)` into `whereNull('branch_id')` — verified via
   `toRawSql()` — so a user with no home branch and no `branches.view_all` sees the *global-branch*
   rows instead of none, where `ActivityLogResource` denies explicitly with `whereRaw('1 = 0')`. No
   live exposure today: all 34 rows have a non-null `branch_id`. Use `accessibleBranchIds()` in
   both resources so there is one rule and one place to get it wrong.
2. **🟡 `recipient` shows a user id, not a recipient.** Verified against live rows: for
   `channel = database` the column holds `"3"` and `"2"` — the `users.id`. It is labelled
   "Recipient" and is the one searchable column, so searching for a name finds nothing. `user` is
   already eager-loaded and never displayed. Show `user.name` and fall back to `recipient` for
   external addresses (mail, and the future SMS/WhatsApp drivers), keeping `recipient` searchable.
3. **🟡 The table grows without bound and cannot be narrowed by date.** One row per recipient per
   channel per sweep, no soft deletes, no pruning: after `transactions` this is the
   fastest-growing table in the schema, and `notification_logs_dedup_idx` grows with it. Add the
   `created_at` range filter (see Index), and take a retention decision: ADR-012 only needs history
   as far back as the longest `repeat_every_days` window, so a scheduled prune is compatible with
   correctness in a way an ad-hoc UI delete never is.
4. **🟡 Payloads contain money, and this screen is not gated on `reports.view_financials`.**
   Verified from live rows: `{"amount": "60000.00", "category": "Office Rent", "due_date":
   "01/08/2026", …}`. `alerts.view_logs` and `reports.view_financials` are seeded independently and
   today both land on super_admin + manager, so there is no live hole — but nothing keeps the two
   sets aligned, and this is the only screen in the panel that shows what was *said* to a person.
   Either note the coupling in the seeder beside `alerts.view_logs`, or redact amount-bearing keys
   from the displayed payload.
5. **🟡 Every status transition writes an activity-log row.** `NotificationLog` uses
   `LogsActivity`, and `markSending()`, `markSent()` and `markDelivered()` each save. Verified in
   the live database: 34 notification_logs have produced 128 `activity_log` rows (34 created + 94
   updated) — roughly four audit rows per delivery. The delivery log is already the audit trail for
   deliveries; duplicating its transitions into ADV-03's trail inflates the busiest audit table for
   information nobody looks for there. Drop `LogsActivity` from this model, or restrict it to
   `created`.
6. **🔵 `cost` is invisible.** `notification_logs.cost` is `decimal(18,2)`, cast `decimal:2`,
   defaulted to 0, documented in the migration as per-message provider cost in DZD — and it appears
   on no column and no infolist entry. Harmless while every driver is free. Recording it now: when
   "what did alerting cost last month" is asked, it is an aggregation, so it goes through
   `ReportService`, never summed on this table.
7. **🔵 The subject is not a link.** `related_type` renders through `class_basename()`, so a row
   says "Booking" without saying which.
8. **🔵 `TranslatesModelLabel` is dead here** — the class declares its own `getModelLabel()` and
   `getPluralModelLabel()`. See [`36-alert-rule.md`](36-alert-rule.md) gap 7.

## Checklist

- [ ] Replace the branch pin with `User::accessibleBranchIds()`, matching `ActivityLogResource`
- [ ] Show `user.name` with `recipient` as fallback; keep `recipient` searchable
- [ ] Add the `created_at` range, `alertRule.type`, `alert_rule_id` and branch filters
- [ ] Link the subject and the alert rule on the index and the view page
- [ ] Take a retention decision and add a scheduled prune command — never a UI delete
- [ ] Decide how amount-bearing payload keys are handled, or document the
      `alerts.view_logs` / `reports.view_financials` coupling in `RolePermissionSeeder`
- [ ] Drop `LogsActivity` from `NotificationLog` (or narrow it to `created`)
- [ ] Add a test asserting this resource exposes no create, edit, delete, bulk or resend action,
      and that a user without `alerts.view_logs` is refused the whole resource
- [ ] Drop the unused `TranslatesModelLabel` trait

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Phase8Test.php
docker compose exec app ./vendor/bin/phpstan analyse app/Filament/Admin/Resources/NotificationLogResource.php app/Filament/Admin/Resources/NotificationLogResource
```

`ResourcePagesRenderTest` does not cover this resource; add it, with a row, and with the view page
opened so the payload entry actually renders.

By hand: as `manager@mcars.dz`, open a delivery and confirm the payload table renders as escaped
text — paste `<b>x</b>` into a payload value in tinker first and confirm it appears literally, not
as bold. Then open the same page as `accountant@mcars.dz` and confirm the whole resource is
refused, not merely the payload.
