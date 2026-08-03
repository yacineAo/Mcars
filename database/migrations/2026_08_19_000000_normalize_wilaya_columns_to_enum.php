<?php

declare(strict_types=1);

use App\Enums\Wilaya;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 35 — one wilaya vocabulary for every table that stores one.
 *
 * The branch form used to hardcode three wilayas ("Alger", "Oran",
 * "Constantine") as a Select while customers and car owners stored free text.
 * From now on every `wilaya` column holds a value of the Wilaya enum (or NULL),
 * enforced by a check constraint — the same idiom as every other enum in the
 * schema (docs/07-enums.md).
 *
 * Only the three values the old hardcoded Select could have produced need
 * normalising, matched case-insensitively (the legacy form and seeders stored
 * title case — "Alger", "Oran", "Constantine"); anything else failing the new
 * constraint stops the migration — which is the honest outcome, since we are
 * pre-go-live and nothing real has been typed into these columns yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = "'".implode("', '", Wilaya::values())."'";

        foreach (['branches', 'customers', 'car_owners'] as $table) {
            DB::table($table)
                ->whereIn(DB::raw('upper(wilaya)'), ['ALGER', 'ORAN', 'CONSTANTINE'])
                ->update(['wilaya' => DB::raw('lower(wilaya)')]);

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_wilaya_check
                 CHECK (wilaya IS NULL OR wilaya IN ({$values}))",
            );
        }
    }

    public function down(): void
    {
        foreach (['branches', 'customers', 'car_owners'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_wilaya_check");
        }
    }
};
