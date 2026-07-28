<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches exist from the foundation phase, not from Phase 10 (ADR-004).
 *
 * The table itself is cheap; retrofitting branch_id onto a populated,
 * append-only ledger later is not — see docs/08-multi-branch-retrofit.md for the
 * five-deploy sequence that avoiding this costs. Multi-branch *behaviour*
 * (global scope, switcher, per-branch cash boxes) still lands in Phase 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            // Short code used in document numbering: CTR-MAIN-2026-000123.
            // This is what keeps per-branch sequences from colliding.
            $table->string('code', 8)->unique();

            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('wilaya')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();

            $table->foreignId('manager_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('timezone', 64)->default('Africa/Algiers');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Exactly one default branch, enforced by the database rather than by a
        // model observer that a seeder or a tinker session can bypass.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX branches_single_default
                 ON branches (is_default) WHERE is_default',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
