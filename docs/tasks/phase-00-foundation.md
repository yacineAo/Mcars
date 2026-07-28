# Phase 0 — Foundation

**Status: ✅ Done** · Depends on: nothing · Closes: no `REQ-*` (enables all)

Scaffold, containers, and the shared primitives every later phase assumes.

---

## Delivered

### Stack — verified at install time, not assumed

| | Chosen | Note |
|---|---|---|
| PHP | 8.4.22 | |
| Laravel | **13.23.0** | |
| Filament | **5.7.3** | v5.0.0 shipped Jan 2026; 38 stable releases |
| PostgreSQL | 16-alpine | |
| Livewire | 4.x | via Filament 5 |

> **Deviation from the docs.** `docs/00`–`08` were written against **Filament v4**. Filament **v5** is
> the current stable and was chosen instead (confirmed with the user). Filament Shield 4.3.1 declares
> `filament/filament:^4.0|^5.0`, and every Spatie package plus maatwebsite/excel supports Laravel 13,
> so nothing was compromised. **API names in `docs/02` may differ from v5** — verify against v5 when
> building resources.

### Packages
`filament/filament` 5.7.3 · `bezhansalleh/filament-shield` 4.3.1 · `spatie/laravel-permission` 8.3.0 ·
`spatie/laravel-medialibrary` 11.23.3 · `spatie/laravel-activitylog` 5.0.0 · `spatie/laravel-backup` 10.3.0 ·
`spatie/laravel-settings` 3.9.0 · `spatie/laravel-pdf` 2.12.0 · `maatwebsite/excel` 3.1.69
Dev: `pestphp/pest` 4.7.5 · `larastan/larastan` 3.10.0 · `laravel/pint` 1.29.3

### Containers — `docker-compose.yml`
`app` (php-fpm 8.4) · `nginx` · `postgres` 16 · `redis` 7 · `mailpit` · `queue` · `scheduler`

- `docker/php/Dockerfile` — extensions via `install-php-extensions`; **Chromium + Node 22 + puppeteer**
  for `spatie/laravel-pdf`, because Arabic RTL contracts render correctly through headless Chrome and
  do not through dompdf. Arabic fonts (`fonts-kacst`, `fonts-hosny-amiri`) installed, or Arabic PDFs
  render as empty boxes.
- Runs as UID/GID 1000 so bind-mounted files stay writable from both sides.

### Extensions
`btree_gist` (required by the Phase 5 booking `EXCLUDE` constraint) and `pg_trgm`, enabled by
`0000_01_01_000000_enable_postgres_extensions.php` — a migration, so CI and production get them too,
plus `docker/postgres/init/01-extensions.sql` for local first-run convenience.

### Primitives

| Class | Notes |
|---|---|
| `App\Support\Money` | Integer minor units. Never floats. `allocate()` splits 100.00 into 33.34 + 33.33 + 33.33 with nothing lost (REQ-07). |
| `App\Support\Casts\MoneyCast` | `decimal(18,2)` ↔ `Money`, refuses cross-currency writes |
| `App\Support\Period` | `[start, end)` to match `tstzrange`. Calendar-aware `previous()`. |
| `App\Support\Sequences\SequenceGenerator` | Row-locked, gap-free. **Throws if called outside a transaction.** |
| `App\Models\Concerns\BelongsToBranch` | Relation + auto-fill. Restricting scope arrives in Phase 10. |
| `App\Models\Concerns\HasAuditColumns` | `created_by_id` / `updated_by_id` |
| `App\Enums\Concerns\HasEnumMeta` | Translated labels, Filament colour/icon, `options()` |

### Scope moved forward from Phase 1
`branches` table, `Branch` model, `BranchFactory`, `BranchSeeder`. `BelongsToBranch` and per-branch
document numbering both need them to exist. A **partial unique index** (`WHERE is_default`) enforces
exactly one default branch in the database, not in an observer a seeder can bypass.

### Config
`Africa/Algiers` (not UTC) — a payment at 00:30 must count toward that day's revenue, which
`now()->toDateString()` under UTC would push to the day before. Locales ar/fr/en with `lang/*/enums.php`.
`config/mcars.php` holds currency, locales, the `branches.enabled` flag and private-disk settings.

### Tooling
Pest 4 (`tests/Pest.php` with `toEqualMoney`/`toBeZeroMoney` expectations and a `money()` helper),
PHPStan level 6 + Larastan, Pint with `declare_strict_types` + `strict_comparison`,
GitHub Actions CI (tests / phpstan / pint as separate jobs, Postgres + Redis services).

---

## Two decisions worth knowing

**Tests run on PostgreSQL, not SQLite.** `phpunit.xml` points at a `mcars_testing` database. SQLite
cannot express the `EXCLUDE` constraint (Phase 5), the ledger immutability trigger (Phase 4), partial
unique indexes or `timestamptz`. On SQLite the suite would go green while proving none of them.

**`RefreshDatabase` wraps Feature tests in a transaction.** Anything asserting behaviour at
transaction level 0 must live in `tests/Unit` — see `tests/Unit/SequenceGuardTest.php`. The first
version of that test sat in Feature and passed vacuously.

---

## Verification — all green

```
Pint      PASS   47 files
PHPStan   [OK] No errors            (level 6)
Pest      34 passed (67 assertions)
HTTP      app 200 · mailpit 200
migrate:fresh --seed → Main Branch / MAIN / is_default = true
```

Two real bugs were caught by these tests and fixed:
1. `Period::previous()` subtracted a fixed duration, so July − 31 days landed on **31 May**, not
   1 June. Month-over-month KPIs would have compared the wrong window. Now calendar-aware.
2. The `SequenceGenerator` transaction guard was untestable under `RefreshDatabase` (see above).

---

## Follow-ups for later phases

- `docs/00`–`08` say **Filament v4**; the build is **v5**. Do a terminology pass when Phase 1 creates
  the first panels and resources.
- `phpstan.neon` ignores `trait.unused` for `app/Models/Concerns/*` and `app/Enums/Concerns/*`. These
  are **self-cleaning**: once Phase 1 applies the traits to `User`, the pattern stops matching and
  `reportUnmatchedIgnoredErrors` fails the build until the entry is deleted. Delete it in Phase 1.
- Docker Hub is unreachable over IPv6 from this host; the Dockerfile fetches `install-php-extensions`
  and Composer over HTTPS instead of `COPY --from`. Keep it that way.
- Run `docker compose` **on the host**, not from inside a container with a mounted socket — bind-mount
  paths resolve against the host filesystem.
