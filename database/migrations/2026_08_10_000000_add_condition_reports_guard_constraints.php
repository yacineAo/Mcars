<?php

declare(strict_types=1);

use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One inspection of each direction per rental — the DB-level backstop of the
        // ConditionReportService guard. Two check-in reports would make the closeout
        // charge basis ambiguous, and (unlike the pre-Phase-8 state of contracts)
        // the table was created without a unique index, so only application code
        // stood between a race and a silent ambiguity.
        Schema::table('condition_reports', function (Blueprint $table): void {
            $table->unique(['booking_id', 'type']);
        });

        // The enum convention: varchar + PHP backed enum + a check constraint.
        // The booking-tables migration shipped without them; add them now.
        DB::statement("ALTER TABLE condition_reports ADD CHECK (type IN ('".implode("','", ConditionReportType::values())."'))");
        DB::statement("ALTER TABLE condition_reports ADD CHECK (fuel_level IS NULL OR fuel_level IN ('".implode("','", FuelLevel::values())."'))");
    }

    public function down(): void
    {
        Schema::table('condition_reports', function (Blueprint $table): void {
            $table->dropUnique(['booking_id', 'type']);
        });

        DB::statement('ALTER TABLE condition_reports DROP CONSTRAINT condition_reports_type_check');
        DB::statement('ALTER TABLE condition_reports DROP CONSTRAINT condition_reports_fuel_level_check');
    }
};
