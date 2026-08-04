# Mcars — Car Rental Management ERP

Operations, accounting, fleet and CRM for a car rental business in Algeria. One staff-only Filament
panel over a double-entry accounting ledger, database-enforced double-booking prevention, e-signed
contracts, and per-car profitability — all derived, nothing stored twice.

Built with **Laravel 13**, **Filament 5**, and **PostgreSQL 16**. Locales are Arabic (RTL), French
and English; currency is DZD, with CCP and BaridiMob as payment methods.

## The core invariant

> Every financial event is recorded through a single central `transactions` ledger. Cash balance,
> profit and per-car profitability are **derived as aggregations over it** — never stored.

Concretely: `bookings.paid_amount`, `customers.outstanding_balance`, `cars.total_revenue` and similar
columns do not exist anywhere in the schema, on purpose. See
[`docs/06-design-decisions.md`](docs/06-design-decisions.md) for the ADRs behind this.

## Documentation

The design lives in [`docs/`](docs/README.md) — read it before changing anything. Start with
[`docs/README.md`](docs/README.md) for the full index, or [`CLAUDE.md`](CLAUDE.md) for the
condensed version used when working with an AI coding agent on this repo.

| Doc | Covers |
|---|---|
| [`docs/00-functional-requirements.md`](docs/00-functional-requirements.md) | Requirements (`REQ-*`/`ADV-*`) and coverage map |
| [`docs/01-database-schema.md`](docs/01-database-schema.md) | Every table — read before any migration |
| [`docs/02-filament-panels.md`](docs/02-filament-panels.md) | The admin panel, resources, widgets |
| [`docs/03-service-layer.md`](docs/03-service-layer.md) | Services and the layering convention |
| [`docs/04-implementation-roadmap.md`](docs/04-implementation-roadmap.md) | The build phases |
| [`docs/05-accounting-model.md`](docs/05-accounting-model.md) | Chart of accounts and posting matrix — read in full before touching money |
| [`docs/06-design-decisions.md`](docs/06-design-decisions.md) | ADRs |
| [`docs/07-enums.md`](docs/07-enums.md) | Enum catalogue |
| [`docs/09-user-guide.md`](docs/09-user-guide.md) | Operating instructions for staff |

**Current state:** phases 0–8 are built. Next up is phase 9 (reports and exports). The system is
staff-only (no customer or owner logins) and charges no tax — see the "Current state" section of
[`CLAUDE.md`](CLAUDE.md) for what that scope cut removed.

## Getting started

Requires Docker. Everything runs through `docker compose`, or the bundled `./sail` wrapper.

```bash
cp .env.example .env

docker compose up -d
# or: ./sail up -d

docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# or: ./sail artisan migrate --seed
```

| Service | URL |
|---|---|
| App | http://localhost:8000 |
| Mailpit | http://localhost:8025 |
| Postgres | localhost:5432 — `mcars` / `mcars` / `secret` |

## Tests and quality gates

```bash
# Tests (PostgreSQL only — SQLite can't express the EXCLUDE constraint or the ledger trigger)
docker compose exec app ./vendor/bin/pest
# or: ./sail pest

# Static analysis
docker compose exec app ./vendor/bin/phpstan analyse
# or: ./sail phpstan analyse

# Code style
docker compose exec app ./vendor/bin/pint
# or: ./sail pint
```

Tests run against a separate `mcars_testing` database, created once with:

```bash
docker compose exec pgsql createdb -U mcars mcars_testing
```

## Stack

- **Laravel 13**, **Filament 5** — one staff panel, clusters, resources, widgets
- **PostgreSQL 16** (`btree_gist`, `pg_trgm`) for the `EXCLUDE` constraint that makes double-booking
  physically impossible, plus **Redis** for cache/queue
- **Spatie** — Permission, Media Library, Activitylog, Backup, Settings
- **Filament Shield** — per-resource permission generation
- **Pest**, **PHPStan** (Larastan), **Pint**
