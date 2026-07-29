# Task Files

One file per build phase. Each is a **self-contained work session**: open it, read the docs it names,
work the checklist, run its verification, tick the `REQ-*` IDs it closes.

## Status

| # | Phase | Status | Closes |
|---|---|---|---|
| [00](phase-00-foundation.md) | Foundation — scaffold, Docker/Postgres, primitives | ✅ **Done** | — |
| [01](phase-01-auth-roles-panels.md) | Auth, roles/permissions, panel skeletons | ✅ **Done** | REQ-20, ADV-06 |
| [02](phase-02-fleet.md) | Fleet — cars, owners, agreements, documents, maintenance | ✅ **Done** | REQ-02, REQ-03, REQ-12, REQ-13 |
| [03](phase-03-crm.md) | CRM — customers, documents | ✅ **Done** | REQ-04 |
| [04](phase-04-ledger-cash-register.md) | **Accounting ledger + cash register** | ✅ **Done** | REQ-08, REQ-09, REQ-10 |
| [05](phase-05-bookings-contracts.md) | Bookings, calendar, contracts, e-signature | ✅ **Done** | REQ-05, REQ-06, ADV-01 |
| [06](phase-06-payments-deposits.md) | Payments, deposits, instalments, fines, payroll | ✅ **Done** | REQ-07, REQ-14, REQ-15, ADV-07 |
| [07](phase-07-dashboards.md) | Dashboards, KPIs, per-car profitability | ✅ **Done** | REQ-01, REQ-11, REQ-18 |
| [08](phase-08-notifications.md) | Notifications and alerts | ✅ **Done** | REQ-17, ADV-05 |
| [09](phase-09-reports.md) | Reports — PDF/Excel exports | ✅ Phase 9a complete | REQ-16 |
| [10](phase-10-portals-audit-backups.md) | Audit, backups, multi-branch | ⬜ | ADV-03/04/06 |

## Dependency order

```
00 Foundation
   └── 01 Auth, Roles, Panels
        ├── 02 Fleet ──┐
        └── 03 CRM ────┤
                       └── 04 Ledger + Cash Register   ← ahead of bookings, deliberately
                            └── 05 Bookings + Contracts
                                 └── 06 Payments, Deposits, Fines, Payroll
                                      └── 07 Dashboards ──┬── 09 Reports
                                           └── 08 Notifications
                                                          └── 10 Audit, Backups, Multi-branch
```

**Why 04 comes before 05:** bookings take deposits and issue invoices, so they need a ledger that
already exists. The ledger has no dependency on bookings — its `booking_id` and `car_id` are nullable
dimensions.

## Standing rules — every session, no exceptions

1. **Only `AccountingService` writes to `transactions`.** New money event → new Poster + a row in the
   posting matrix in [`../05-accounting-model.md`](../05-accounting-model.md).
2. **No stored balances.** Reaching for `paid_amount` or `current_balance` means writing a query
   instead. Banned list in [`../01-database-schema.md`](../01-database-schema.md).
3. **`branch_id` on every new operational table.**
4. **Update the docs in the same session as the code.** A schema change not reflected in `docs/` will
   be contradicted by the next phase.
5. **Each phase ships its tests**, and they run on PostgreSQL.
6. **Every money- or document-touching model gets an activity log** from the phase that creates it.

## Gates each phase must pass

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pest
```

## Blocked on business answers

| Question | Blocks | Needed by |
|---|---|---|
| Revenue recognition — at pickup, full amount? | Phase 04 | **before 04 starts** — changing it later means restating history |
| TVA / VAT treatment | Phase 09 tax report | before 09 |
| Owner disclosure level for fixed-rent agreements | Phase 10 statement | before 10 |
| ~~WhatsApp provider~~ — **closed**: Discord webhooks chosen | Phase 08 | settled |
| Depreciation on company-owned cars (E48) | Phase 04 posting matrix | before 04 |

Full context in [`../README.md`](../README.md) § Open questions.
