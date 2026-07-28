<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free, per-branch, per-year document numbering.
 *
 * Contract numbers are legal identifiers, so `id` is not usable: a rolled-back
 * transaction burns an auto-increment value and leaves a hole an auditor will
 * ask about. Allocation happens under SELECT ... FOR UPDATE inside the same
 * transaction as the document insert — see App\Support\Sequences\SequenceGenerator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->id();

            // contract | booking | transaction | payment | expense | invoice
            $table->string('key', 32);

            $table->foreignId('branch_id')->nullable()
                ->constrained('branches')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');

            $table->string('prefix', 16);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->unsignedBigInteger('next_number')->default(1);

            $table->timestamps();

            // One counter per key/branch/year. This unique index is also the
            // lock target that makes concurrent allocation safe.
            $table->unique(['key', 'branch_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
