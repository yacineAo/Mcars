# Phase 8 — Notifications & Alerts

**Status: ✅** · Depends on: Phases 2, 3, 5, 6 · Closes: **REQ-17**, **REQ-12** (alerts),
**REQ-13** (alerts), **ADV-05**

The system says what needs attention, before it is late.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 5 ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-012

## Business answer (settled)

**WhatsApp was dropped in favour of Discord webhooks.** No template pre-approval, no per-message cost,
no provider onboarding. The swappable-driver requirement stands and is the reason this was a config
change rather than a rewrite: adding WhatsApp later is one driver class, one `NotificationChannel`
case, one migration widening the `channel` CHECK constraint, and one line in
`config/notifications.php`. No calling code changes.

## Deliverables

### Tables
- [x] `alert_rules` — `type`, `days_before`, `repeat_every_days`, `max_repeats`, `channels`,
      `recipient_roles`, `template_key`, `branch_id` (null = global), `is_active`
- [x] `notification_logs` — channel, recipient, template, payload, related subject, status, provider,
      `provider_message_id`, error, attempts, timestamps, cost
- [x] Laravel `notifications` table — **`data` is `jsonb`, not Laravel's stock `text`**: Filament's
      bell filters on `data->>'format'` and Postgres has no `->>` operator for `text`, so a `text`
      column 500s the topbar on every page

### Services
- [x] **`NotificationService`** — hourly rule evaluation, recipient resolution, dedup
- [x] **`MessagingService`** — mail, in-app and Discord behind one `MessageDriver` interface;
      per-locale templates (ar/fr/en); queued with backoff. **Drivers swappable via
      `config/notifications.php`.**
- [x] Ten `AlertDetector` classes, one per type, resolved through `DetectorRegistry`

### Alert types (REQ-17)
- [x] Return due · booking overdue · customer payment overdue · owner instalment due
- [x] Vehicle documents expiring (insurance / registration / inspection, via `car_documents.type`) ·
      driving licence expiring (reads `customers.license_expiry_date`)
- [x] Maintenance due (km **or** date, whichever comes first)
- [x] Recurring expense due
- [x] Cash variance detected (reads the `Disputed` state `CashRegisterService` already sets)
- [~] **Backup failed — rule and channels configured, but no emitter yet.** There is no backups table
      until Phase 10, and a backup failure is an event, not a pollable state.
      `BackupFailedDetector` returns nothing by design; Phase 10's backup job should call
      `NotificationService::alertOnce()` and this detector should then be deleted.

### ⚠ Deduplication is a requirement, not an optimisation (ADR-012)
- [x] Before sending, check `notification_logs` for the same
      `(template_key, related_type, related_id, channel)` within the rule's `repeat_every_days`
- [x] Covering index `notification_logs_dedup_idx` on
      `(template_key, related_type, related_id, channel, created_at DESC)`
- [x] The window counts **Queued and Sending, not just Sent** — an alert already on the queue is one
      the recipient is about to get. Keying on `sent_at` would let every hourly sweep re-queue the
      same alert while the first was still waiting.
- [x] `max_repeats` enforced as a hard ceiling per subject
- [x] A channel whose driver is switched off is **not queued at all**. A cancelled row deliberately
      does not hold the window shut, so queueing an undeliverable channel would re-queue it forever.

An insurance policy expiring in 30 days must produce a handful of alerts, not thirty. A system that
cries wolf daily gets muted — and then the one alert that mattered is missed too. Alert fatigue does
not degrade the feature gracefully, it destroys it.

### UI
- [x] In-app bell on the admin panel, filtered by `notifiable` (the other two panels were withdrawn)
- [x] Per-user daily digest (`users.notification_digest` + `notification_digest_at`, `alerts:digest`)
- [x] **`AlertRuleResource`** — gated on the `alerts.manage` permission
- [x] `NotificationLogResource` — view-only, gated on `alerts.view_logs`, branch-pinned server-side

## Tests

`tests/Feature/Phase8Test.php` — 20 tests, 50 assertions.

- [x] Each rule fires at the right lead time and **not before**
- [x] Deduplication verified — 30 daily runs produce 5 alerts, not 30
- [x] A failed send is retried, logged, and **does not block the request** (all sends queued; a
      permanent 4xx is marked non-retryable rather than burning the retry budget)
- [x] Recipients correctly scoped — an owner alert never reaches another owner
- [x] A branch-scoped rule notifies only that branch's recipients
- [x] A queued-but-unsent alert suppresses a duplicate
- [x] Discord embed payload shape, per-type webhook routing, permanent-rejection handling

## Definition of done

**Verified.** An insurance policy set to expire in 7 days produced an email (confirmed in Mailpit)
and an in-app bell entry; a second sweep queued **0**. Discord was correctly skipped with no webhook
configured. Gates green: Pint 396 files pass, PHPStan 455 (unchanged pre-existing baseline — Phase 8
files contribute 0), Pest 183 passed.

## Recipient scoping — the rule that matters

`car_owner` and `client` are **subject-bound** roles. A rule naming one resolves only to the users the
subject itself points at (`AlertSubject::$targetedUserIds`), never to every holder of the role. Staff
roles fan out across the branch; portal roles never do. Getting this backwards would email every car
owner about one owner's instalment.

## Note for Phase 10

`AlertRule` overrides `BelongsToBranch::resolveBranchId()` to return null. On an operational row a
null `branch_id` is a gap to fill; on an alert rule it is data meaning "all branches". The Phase 10
branch scope must not restrict `alert_rules` by branch either, or global rules stop firing.
