<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * See docs/resource/11-financial-account.md, "Enforce a single is_default_for_cash".
 * Mirrors branches_single_default (2026_08_18_000000): the app-level reset in
 * CreateFinancialAccount/EditFinancialAccount is now backed by FinancialAccountService,
 * and this index is the DB-level guarantee that survives any path that skips it.
 * Soft-delete-safe from the start, unlike the original branches index — a trashed
 * account must drop out of the race so its replacement is not refused a collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX financial_accounts_single_default_for_cash
             ON financial_accounts (is_default_for_cash) WHERE is_default_for_cash AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS financial_accounts_single_default_for_cash');
    }
};
