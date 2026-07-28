<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. Chart of accounts
        // ----------------------------------------------------------------
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('name_fr', 255)->nullable();
            $table->string('type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts');
            $table->string('normal_balance', 10);
            $table->boolean('is_cash_equivalent')->default(false);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('parent_id');
        });

        // ----------------------------------------------------------------
        // 2. Financial accounts
        // ----------------------------------------------------------------
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('ledger_account_id')->unique()->constrained('chart_of_accounts');
            $table->string('name', 255);
            $table->string('type', 30);
            $table->string('account_number', 50)->nullable();
            $table->string('rib', 50)->nullable();
            $table->string('holder_name', 255)->nullable();
            $table->string('currency', 3)->default('DZD');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->date('opened_on');
            $table->jsonb('allowed_payment_methods')->nullable();
            $table->boolean('is_default_for_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
        });

        // ----------------------------------------------------------------
        // 3. Expense categories
        // ----------------------------------------------------------------
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('name_fr', 255)->nullable();
            $table->string('slug', 100)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories');
            $table->foreignId('ledger_account_id')->constrained('chart_of_accounts');
            $table->boolean('is_car_related')->default(false);
            $table->boolean('is_recurring_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
        });

        // ----------------------------------------------------------------
        // 4. Transactions (the single ledger)
        // ----------------------------------------------------------------
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('reference', 50)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->date('occurred_on');
            $table->timestampTz('posted_at');
            $table->foreignId('debit_account_id')->constrained('chart_of_accounts');
            $table->foreignId('credit_account_id')->constrained('chart_of_accounts');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('DZD');
            $table->decimal('exchange_rate', 18, 6)->default(1.0);
            $table->string('type', 50);
            $table->string('payment_method', 30)->nullable();
            $table->text('description')->nullable();
            // Dimensions — nullable FK constraints only where the target table exists
            $table->foreignId('car_id')->nullable()->constrained('cars');
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('car_owner_id')->nullable()->constrained('car_owners');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories');
            // Provenance
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('cash_session_id')->nullable()->index();
            // Corrections
            $table->unsignedBigInteger('reverses_transaction_id')->nullable()->index();
            $table->unsignedBigInteger('reversed_by_transaction_id')->nullable()->index();
            $table->boolean('is_reversal')->default(false);
            $table->jsonb('meta')->nullable();

            $table->index('occurred_on');
            $table->index(['branch_id', 'occurred_on']);
            $table->index(['debit_account_id', 'occurred_on']);
            $table->index(['credit_account_id', 'occurred_on']);
            $table->index(['car_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_diff_accounts CHECK (debit_account_id <> credit_account_id)');

        // Add FK for reversal self-refs (table must exist first)
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_reverses_fk FOREIGN KEY (reverses_transaction_id) REFERENCES transactions(id)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_reversed_by_fk FOREIGN KEY (reversed_by_transaction_id) REFERENCES transactions(id)');

        // ----------------------------------------------------------------
        // 5. Cash sessions
        // ----------------------------------------------------------------
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->foreignId('opened_by_id')->constrained('users');
            $table->timestampTz('opened_at');
            $table->decimal('opening_float', 18, 2)->default(0);
            $table->foreignId('closed_by_id')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->decimal('counted_amount', 18, 2)->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('reconciled_by_id')->nullable()->constrained('users');
            $table->timestampTz('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['financial_account_id', 'status']);
        });

        DB::statement("
            CREATE UNIQUE INDEX uq_cash_sessions_one_open
            ON cash_sessions (financial_account_id)
            WHERE status = 'open'
        ");

        // Now add the cash_session_id FK on transactions
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_cash_session_fk FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)');

        // ----------------------------------------------------------------
        // 6. Expenses
        // ----------------------------------------------------------------
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignId('car_id')->nullable()->constrained('cars');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('total_amount', 18, 2);
            $table->date('incurred_on');
            $table->text('description')->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts');
            $table->timestampTz('paid_at')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->text('recurrence_rule')->nullable();
            $table->foreignId('parent_expense_id')->nullable()->constrained('expenses');
            $table->date('next_occurrence_on')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->foreignId('created_by_id')->nullable()->constrained('users');
            $table->foreignId('updated_by_id')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['expense_category_id', 'incurred_on']);
            $table->index(['car_id', 'incurred_on']);
        });

        // ----------------------------------------------------------------
        // 7. Cash register entries view
        // ----------------------------------------------------------------
        DB::statement("
            CREATE OR REPLACE VIEW cash_register_entries AS
            SELECT t.id, t.reference, t.occurred_on, t.posted_at, t.branch_id, t.cash_session_id,
                   t.debit_account_id AS financial_ledger_account_id, 'in' AS direction, t.amount,
                   t.description, t.payment_method, t.created_by_id, t.source_type, t.source_id
              FROM transactions t
              JOIN chart_of_accounts a ON a.id = t.debit_account_id
             WHERE a.is_cash_equivalent
             UNION ALL
            SELECT t.id, t.reference, t.occurred_on, t.posted_at, t.branch_id, t.cash_session_id,
                   t.credit_account_id, 'out' AS direction, t.amount,
                   t.description, t.payment_method, t.created_by_id, t.source_type, t.source_id
              FROM transactions t
              JOIN chart_of_accounts a ON a.id = t.credit_account_id
             WHERE a.is_cash_equivalent
        ");

        // ----------------------------------------------------------------
        // 8. Immutability trigger on transactions
        // ----------------------------------------------------------------
        DB::statement("
            CREATE OR REPLACE FUNCTION block_transaction_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Transactions are append-only. Use AccountingService::reverse() to correct mistakes.';
                ELSIF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Transactions are append-only. Deletion is not permitted.';
                END IF;
                RETURN NULL;
            END;
            $$
        ");
        DB::statement('
            CREATE TRIGGER trg_block_transaction_mutation
            BEFORE UPDATE OR DELETE ON transactions
            FOR EACH ROW EXECUTE FUNCTION block_transaction_mutation()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_block_transaction_mutation ON transactions');
        DB::statement('DROP FUNCTION IF EXISTS block_transaction_mutation()');
        DB::statement('DROP VIEW IF EXISTS cash_register_entries');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('chart_of_accounts');
    }
};
