# Phase 8 — Notifications & Alerts

**Status: ⬜** · Depends on: Phases 2, 3, 5, 6 · Closes: **REQ-17**, **REQ-12** (alerts),
**REQ-13** (alerts), **ADV-05**

The system says what needs attention, before it is late.

## Read first
[`../01-database-schema.md`](../01-database-schema.md) §Module 5 ·
[`../06-design-decisions.md`](../06-design-decisions.md) ADR-012

## Blocked on a business answer

**WhatsApp provider** — official Cloud API (message templates need pre-approval, which takes days) or
a third-party gateway? This changes the driver and the template workflow. Settle it before starting.

## Deliverables

### Tables
- [ ] `alert_rules` — `type`, `days_before`, `repeat_every_days`, `max_repeats`, `channels`,
      `recipient_roles`, `template_key`, `branch_id` (null = global), `is_active`
- [ ] `notification_logs` — channel, recipient, template, payload, related subject, status, provider,
      `provider_message_id`, error, attempts, timestamps, cost
- [ ] Laravel `notifications` table

### Services
- [ ] **`NotificationService`** — hourly scheduled rule evaluation, recipient resolution, dedup
- [ ] **`MessagingService`** full build — mail, WhatsApp Cloud API, SMS gateway behind one interface;
      per-locale templates (ar/fr/en); queued with retry; delivery webhooks updating
      `notification_logs`. **Drivers swappable via config** — the WhatsApp provider will change, and
      calling code must not.

### Alert types (REQ-17)
- [ ] Return due tomorrow · booking overdue · customer payment overdue · owner instalment due
- [ ] Insurance / registration / technical inspection expiring · driving licence expiring
- [ ] Maintenance due (km **or** date, whichever comes first)
- [ ] Recurring expense due (office rent, internet, electricity)
- [ ] Cash variance detected · backup failed

### ⚠ Deduplication is a requirement, not an optimisation (ADR-012)
- [ ] Before sending, check `notification_logs` for the same
      `(template_key, related_type, related_id, channel)` within the rule's `repeat_every_days`
- [ ] Index on `(template_key, related_type, related_id, sent_at)` to make that check cheap

An insurance policy expiring in 30 days must produce a handful of alerts, not thirty. A system that
cries wolf daily gets muted — and then the one alert that mattered is missed too. Alert fatigue does
not degrade the feature gracefully, it destroys it.

### UI
- [ ] In-app notification bell on all three panels, filtered by `notifiable`
- [ ] Per-user daily digest option
- [ ] **`AlertRuleResource`** — lead times managed by the manager, not hardcoded by a developer
- [ ] `NotificationLogResource` (view-only delivery audit)

## Tests

- [ ] Each rule fires at the right lead time and **not before**
- [ ] Deduplication verified — 30 daily runs produce the configured number of alerts, not 30
- [ ] A failed WhatsApp send is retried, logged, and **does not block the request** (all sends queued;
      a provider timeout must never stall a receptionist mid-checkout)
- [ ] Recipients correctly scoped — an owner alert never reaches another owner
- [ ] A branch-scoped rule notifies only that branch's recipients

## Definition of done

Set an insurance policy to expire in 7 days; the alert arrives by email and WhatsApp and appears in
the bell — **once**. Gates green.
