<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table): void {
            // A waived line is a business decision, not a delete: the reason is
            // mandatory and the actor is stamped, mirroring owner_installments.
            $table->text('waived_reason')->nullable();
            $table->timestampTz('waived_at')->nullable();
            $table->foreignId('waived_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waived_by_id');
            $table->dropColumn(['waived_reason', 'waived_at']);
        });
    }
};
