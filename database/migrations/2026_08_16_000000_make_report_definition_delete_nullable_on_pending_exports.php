<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a saved report stops the schedule but must not discard the audit of
 * what was emailed and when — the run history is the only record of a report
 * that worked, and of one that silently failed. `nullOnDelete` keeps the rows;
 * they simply stop pointing at the definition. The runs table renders a null
 * definition the same way it renders an archive row with no definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_exports', function (Blueprint $table) {
            $table->dropForeign(['report_definition_id']);
            $table->foreign('report_definition_id')
                ->references('id')
                ->on('report_definitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_exports', function (Blueprint $table) {
            $table->dropForeign(['report_definition_id']);
            $table->foreign('report_definition_id')
                ->references('id')
                ->on('report_definitions')
                ->cascadeOnDelete();
        });
    }
};
