<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deposits` was indexed on `customer_id` and `status` only, but the deposits
 * screen orders by `held_at` on every load and now filters on a `held_at` range
 * as well. Both were sequential scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->index('held_at');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropIndex(['held_at']);
        });
    }
};
