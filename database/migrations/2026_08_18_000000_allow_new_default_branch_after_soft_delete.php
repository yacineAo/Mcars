<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 35 — see docs/resource/35-branch.md, the "single-default index" gap.
 *
 * The original index (`WHERE is_default`) only guarantees one row *literally*
 * holding `is_default = true`. Because branches are soft-deleted, deleting the
 * default branch leaves such a row in place (only deleted_at is set), so:
 *   - promoting a replacement collides with the ghost row and fails, and
 *   - the flag looks claimed forever even though nobody runs that branch.
 *
 * The predicate now ignores soft-deleted rows: the deleted branch drops out of
 * the race, and exactly one *live* branch can hold the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS branches_single_default');
        DB::statement(
            'CREATE UNIQUE INDEX branches_single_default
             ON branches (is_default) WHERE is_default AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS branches_single_default');
        DB::statement(
            'CREATE UNIQUE INDEX branches_single_default
             ON branches (is_default) WHERE is_default',
        );
    }
};
