<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;

/**
 * Conventions the schema must keep, asserted rather than trusted.
 *
 * Each of these encodes a defect that actually shipped:
 *
 * - Eleven tables used HasAuditColumns without having the columns. The trait
 *   stamps created_by_id whenever someone is logged in, so creating a payment,
 *   deposit, vendor or car owner threw a SQL error in the running application.
 *   No test caught it because tests create records without actingAs().
 * - payments/deposits/employee_advances.financial_account_id pointed at
 *   chart_of_accounts instead of financial_accounts. The foreign key passed only
 *   while the two id ranges happened to overlap.
 * - Phase 6 shipped no CHECK constraints on its enum columns, so any string could
 *   be written and would only surface later as an enum cast failure.
 */

/** Every model using the trait must have somewhere to put the values. */
it('has audit columns on every table whose model stamps them', function () {
    $missing = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        if (! str_contains((string) file_get_contents($file), 'HasAuditColumns')) {
            continue;
        }

        $class = 'App\\Models\\'.basename($file, '.php');
        $table = (new $class)->getTable();

        foreach (['created_by_id', 'updated_by_id'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    expect($missing)->toBe([]);
});

/** financial_account_id means a cash box or bank, never a bookkeeping account. */
it('points every financial_account_id at financial_accounts', function () {
    $wrong = collect(\DB::select(<<<'SQL'
        SELECT tc.table_name, ccu.table_name AS references_table
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu
          ON tc.constraint_name = kcu.constraint_name
        JOIN information_schema.constraint_column_usage ccu
          ON tc.constraint_name = ccu.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND kcu.column_name = 'financial_account_id'
    SQL))
        ->reject(fn (object $row): bool => $row->references_table === 'financial_accounts')
        ->map(fn (object $row): string => "{$row->table_name} -> {$row->references_table}")
        ->values()
        ->all();

    expect($wrong)->toBe([]);
});

/**
 * A status column with no CHECK constraint accepts anything, and the mistake only
 * appears much later when Eloquent tries to cast the value to its enum.
 */
it('constrains the enum columns that have a backing enum', function () {
    $expected = [
        'payments' => ['method', 'status', 'direction'],
        'payment_schedules' => ['status'],
        'deposits' => ['method', 'status'],
        'deposit_deductions' => ['reason'],
        'fines' => ['type', 'liability', 'status'],
        'owner_installments' => ['status'],
        'payroll_runs' => ['status'],
    ];

    $unconstrained = [];

    foreach ($expected as $table => $columns) {
        $definitions = collect(\DB::select(<<<'SQL'
            SELECT pg_get_constraintdef(con.oid) AS def
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            WHERE con.contype = 'c' AND rel.relname = ?
        SQL, [$table]))->pluck('def')->implode(' ');

        foreach ($columns as $column) {
            if (! str_contains($definitions, $column)) {
                $unconstrained[] = "{$table}.{$column}";
            }
        }
    }

    expect($unconstrained)->toBe([]);
});

/** The ledger is append-only at the database level, not merely in Eloquent. */
it('keeps transactions free of a soft-delete column', function () {
    expect(Schema::hasColumn('transactions', 'deleted_at'))->toBeFalse();
});

/** Stored balances are forbidden — every figure is derived from the ledger. */
it('has none of the banned stored-balance columns', function () {
    $banned = [
        'bookings' => 'paid_amount',
        'customers' => 'outstanding_balance',
        'cars' => 'total_revenue',
        'financial_accounts' => 'current_balance',
        'owner_installments' => 'amount_paid',
    ];

    $present = [];

    foreach ($banned as $table => $column) {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            $present[] = "{$table}.{$column}";
        }
    }

    expect($present)->toBe([]);
});
