<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX customers_national_id_unique ON customers (national_id) WHERE national_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX customers_driving_license_number_unique ON customers (driving_license_number) WHERE driving_license_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX customers_phone_unique ON customers (phone)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_national_id_unique');
        DB::statement('DROP INDEX IF EXISTS customers_driving_license_number_unique');
        DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
    }
};
