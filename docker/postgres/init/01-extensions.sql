-- Runs only on first initialisation of the postgres-data volume, as a local
-- convenience. The authoritative enablement is the Laravel migration
-- 0000_01_01_000000_enable_postgres_extensions.php, which also covers CI,
-- staging and production where this script never runs.

-- btree_gist: required by the bookings EXCLUDE constraint (Phase 5) that makes
-- double-booking physically impossible. Without it that migration fails.
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- pg_trgm: fuzzy search on plates, chassis numbers, customer names.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
