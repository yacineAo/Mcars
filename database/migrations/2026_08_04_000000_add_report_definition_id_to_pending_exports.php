<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_exports', function (Blueprint $table) {
            $table->foreignId('report_definition_id')
                ->nullable()
                ->after('user_id')
                ->constrained('report_definitions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_exports', function (Blueprint $table) {
            $table->dropForeign(['report_definition_id']);
            $table->dropColumn('report_definition_id');
        });
    }
};
