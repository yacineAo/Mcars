<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * See docs/resource/03-car-owner.md. Mirrors customers_national_id_unique
 * (2026_07_28_171000): a national ID card number is unique per person in
 * reality, but the column stayed nullable, so the partial index excludes
 * NULL rather than forcing every owner to have one on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX car_owners_national_id_unique ON car_owners (national_id) WHERE national_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS car_owners_national_id_unique');
    }
};
