<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `owner_installments.total_installments` becomes nullable: NULL means the
 * agreement is open-ended, and there is no instalment count to show.
 *
 * The generator used to write 999 as a sentinel for "indefinite", which leaked
 * into every screen as "3 of 999". Owner rent is a monthly accrual that runs
 * until the agreement ends, so the honest value is "no total yet" — reports
 * print the sequence number alone instead of inventing a denominator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_installments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('total_installments')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing rows are all we have to go back to: restore the sentinel on
        // rows that were open-ended (never had a real count) so nothing is null
        // under the old NOT NULL contract.
        DB::table('owner_installments')
            ->whereNull('total_installments')
            ->update(['total_installments' => 999]);

        Schema::table('owner_installments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('total_installments')->nullable(false)->change();
        });
    }
};
