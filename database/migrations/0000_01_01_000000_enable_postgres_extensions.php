<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs before everything else (0000_ prefix) because later migrations depend on
 * these extensions existing.
 *
 * btree_gist is not optional: the bookings EXCLUDE constraint in Phase 5 is what
 * makes double-booking physically impossible (REQ-05, ADR-002). Without the
 * extension that migration fails outright.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $extensions = ['btree_gist', 'pg_trgm'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->extensions as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        // Deliberately not dropped. Other objects (indexes, constraints) depend on
        // them, and dropping an extension cascades in ways a rollback should not.
    }
};
