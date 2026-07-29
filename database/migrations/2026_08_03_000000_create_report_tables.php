<?php

declare(strict_types=1);

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_type');
            $table->string('format');
            $table->json('parameters');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE pending_exports ADD CHECK (report_type IN ('".implode("','", ReportType::values())."'))");
        DB::statement("ALTER TABLE pending_exports ADD CHECK (format IN ('".implode("','", ExportFormat::values())."'))");
        DB::statement("ALTER TABLE pending_exports ADD CHECK (status IN ('pending','processing','completed','failed'))");

        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('report_type');
            $table->string('format');
            $table->json('parameters');
            $table->string('schedule_cron')->nullable();
            $table->string('schedule_email')->nullable();
            $table->boolean('schedule_enabled')->default(false);
            $table->timestampTz('last_sent_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'schedule_enabled']);
        });

        DB::statement("ALTER TABLE report_definitions ADD CHECK (report_type IN ('".implode("','", ReportType::values())."'))");
        DB::statement("ALTER TABLE report_definitions ADD CHECK (format IN ('".implode("','", ExportFormat::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('pending_exports');
    }
};
