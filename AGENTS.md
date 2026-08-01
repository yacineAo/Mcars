# Repository Guidelines

## Project Structure & Module Organization

Mcars is a Laravel 13 / Filament 5 car-rental ERP. Application code lives in `app/`: models in `app/Models`, services in `app/Services`, Filament resources in `app/Filament/Admin`, and money/date primitives in `app/Support`. Database migrations, factories, and seeders are under `database/`. Blade, JavaScript, and CSS assets live in `resources/`. Pest tests are split into `tests/Unit` and `tests/Feature`.

Read `docs/README.md` before changing a domain area. In particular, read `docs/01-database-schema.md` before migrations and `docs/05-accounting-model.md` before touching financial flows.

## Build, Test, and Development Commands

- `docker compose up -d` — start the app, PostgreSQL, and supporting services.
- `docker compose exec app php artisan migrate --seed` — apply schema and seed data.
- `docker compose exec app ./vendor/bin/pest` — run the full Pest suite.
- `docker compose exec app ./vendor/bin/pest tests/Feature/Phase6Test.php` — run a focused suite.
- `docker compose exec app ./vendor/bin/phpstan analyse` — run static analysis.
- `docker compose exec app ./vendor/bin/pint` — apply PHP style rules.
- `npm run dev` / `npm run build` — develop or build Vite frontend assets.

The `./sail` wrapper provides equivalents, e.g. `./sail pest`.

## Coding Style & Naming Conventions

Use PHP 8.3, `declare(strict_types=1)`, Laravel Pint, and four-space indentation. Follow PSR-4 names: `App\Services\Booking\BookingService`, `BookingResource`, and `CreateBooking`. Use backed enums for constrained values and `decimal(18,2)` with `Money`/`MoneyCast` for money—never floats.

Keep business decisions in services or actions, not Filament resources. Only `AccountingService` writes ledger transactions; ledger rows are append-only. Financial views must use `ReportService` aggregations and respect `reports.view_financials` plus branch access.

## Testing Guidelines

Write Pest tests alongside the affected layer. Use `tests/Feature` for application and database behavior; use `tests/Unit` for isolated primitives or transaction-level cases. Name tests as readable behavior, for example `it('allocates an instalment plan without losing a centime', ...)`. The suite requires PostgreSQL (`mcars_testing`), not SQLite.

Run focused tests, then the relevant phase suite and Pint before submitting.

## Commit & Pull Request Guidelines

Use Conventional Commit-style messages used by the repository: `feat(payment): add schedule generator` or `fix(booking): prevent overlap`. Keep commits scoped. Pull requests should explain the user-visible and domain impact, link the relevant `REQ-*`/issue, list verification commands, and include screenshots for Filament UI changes. Call out migrations, ledger changes, and permission changes explicitly.

## Security & Configuration

Never commit `.env` secrets. Preserve private media storage and branch scoping. Treat customer, payment, and accounting data as sensitive; use permissions rather than role-name checks where the codebase provides a capability gate.
