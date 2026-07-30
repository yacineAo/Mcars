<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX maintenance_logs_open_unique ON maintenance_logs (car_id, type) WHERE status IN ('scheduled', 'in_progress')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS maintenance_logs_open_unique');
    }
};
