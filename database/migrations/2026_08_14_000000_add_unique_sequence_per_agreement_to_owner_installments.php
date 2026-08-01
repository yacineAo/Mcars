<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One document run per agreement: `sequence_number` must be unique within it.
 *
 * Both the generator and the manual correction path assign max+1, which races
 * under concurrent requests. The unique index turns a silent duplicate document
 * number into a loud failure instead of two instalments sharing a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_installments', function (Blueprint $table): void {
            $table->unique(['car_ownership_agreement_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('owner_installments', function (Blueprint $table): void {
            $table->dropUnique('owner_installments_car_ownership_agreement_id_sequence_number_unique');
        });
    }
};
