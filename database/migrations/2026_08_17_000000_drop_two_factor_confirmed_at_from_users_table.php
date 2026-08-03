<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 33 — the 2FA columns are wired to Filament's native multi-factor
 * authentication, which manages `two_factor_secret` and `two_factor_recovery_codes`
 * itself and never reads `two_factor_confirmed_at` (a legacy of phase-01's own
 * scheme). The column is dead schema — nothing reads or writes it, and keeping it
 * implies a confirmation state the panel does not track.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }
};
