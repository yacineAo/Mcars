<?php

declare(strict_types=1);

use App\Enums\AdvanceStatus;
use App\Enums\CommissionStatus;
use App\Enums\DeductionReason;
use App\Enums\DepositStatus;
use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayrollStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------------
        // Employees (REQ-15) — must be before payments, owner_installments, etc.
        // -----------------------------------------------------------------------
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number', 32)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('national_id', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->string('contract_type', 32)->default('cdi');
            $table->string('salary_type', 16)->default('monthly');
            $table->decimal('base_salary', 18, 2);
            $table->jsonb('commission_scheme')->nullable();
            $table->string('bank_rib')->nullable();
            $table->string('ccp_account')->nullable();
            $table->string('social_security_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('department');
        });

        // -----------------------------------------------------------------------
        // Payments (REQ-07)
        // -----------------------------------------------------------------------
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16); // inbound | outbound
            $table->nullableMorphs('payable'); // Booking, Contract, OwnerInstallment, Fine, Expense, PayrollItem, Deposit
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_owner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 32);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('DZD');
            $table->date('paid_at');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('status', 24)->default('completed');
            $table->string('external_reference')->nullable();
            $table->date('cheque_due_date')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('direction');
            $table->index('paid_at');
            $table->index('customer_id');
            $table->index('car_owner_id');
            $table->index('employee_id');
        });

        // -----------------------------------------------------------------------
        // Payment schedules / instalment plans (REQ-07)
        // -----------------------------------------------------------------------
        Schema::create('payment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('schedulable'); // Booking or Contract
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->string('status', 24)->default('pending');
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('due_date');
        });

        // -----------------------------------------------------------------------
        // Deposits (ADV-07)
        // -----------------------------------------------------------------------
        Schema::create('deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method', 32);
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->timestampTz('held_at');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default(DepositStatus::Held->value);
            $table->timestampTz('settled_at')->nullable();
            $table->foreignId('settled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
        });

        // -----------------------------------------------------------------------
        // Fines (REQ-14) — must be before deposit_deductions
        // -----------------------------------------------------------------------
        Schema::create('fines', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_id')->constrained();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('authority')->nullable();
            $table->string('notice_number')->nullable();
            $table->timestampTz('violation_at');
            $table->string('location')->nullable();
            $table->timestampTz('received_at');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('late_penalty_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->string('liability', 32)->default('pending_review');
            $table->foreignId('liability_determined_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('liability_determined_at')->nullable();
            $table->text('liability_note')->nullable();
            $table->string('status', 32)->default('new');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('car_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('violation_at');
        });

        // -----------------------------------------------------------------------
        // Deposit deductions (ADV-07)
        // -----------------------------------------------------------------------
        Schema::create('deposit_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->foreignId('condition_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('deposit_id');
        });

        // -----------------------------------------------------------------------
        // Owner instalments (REQ-03, REQ-19)
        // -----------------------------------------------------------------------
        Schema::create('owner_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_ownership_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_owner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->unsignedSmallInteger('total_installments');
            $table->date('period_month');
            $table->date('due_date');
            $table->decimal('amount_due', 18, 2);
            $table->string('status', 24)->default('pending');
            $table->foreignId('accrual_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->text('waived_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('car_owner_id');
            $table->index('car_id');
            $table->index('period_month');
            $table->index('status');
        });

        // -----------------------------------------------------------------------
        // Payroll runs (REQ-15)
        // -----------------------------------------------------------------------
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_month'); // first of month
            $table->string('status', 24)->default(PayrollStatus::Draft->value);
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('period_month');
            $table->index('status');
        });

        // -----------------------------------------------------------------------
        // Payroll items (REQ-15)
        // -----------------------------------------------------------------------
        Schema::create('payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('commissions_amount', 18, 2)->default(0);
            $table->decimal('bonuses_amount', 18, 2)->default(0);
            $table->decimal('overtime_amount', 18, 2)->default(0);
            $table->decimal('advances_deducted', 18, 2)->default(0);
            $table->decimal('absences_deduction', 18, 2)->default(0);
            $table->decimal('social_contributions', 18, 2)->default(0);
            $table->decimal('other_deductions', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index('employee_id');
        });

        // -----------------------------------------------------------------------
        // Employee advances (REQ-15)
        // -----------------------------------------------------------------------
        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('advanced_on');
            $table->text('reason')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('outstanding');
            $table->foreignId('recovered_in_payroll_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('status');
        });

        // -----------------------------------------------------------------------
        // Commissions (REQ-15)
        // -----------------------------------------------------------------------
        Schema::create('commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('basis_amount', 18, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('pending');
            $table->foreignId('payroll_item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('earned_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('status');
            // The sweep queue (payroll_item_id IS NULL) is the screen's default
            // view and the month-end sweep's query; like the CHECK constraints
            // below, this landed late and applies on fresh migrations only.
            $table->index('payroll_item_id');
        });

        // ----------------------------------------------------------------
        // Enum CHECK constraints
        // ----------------------------------------------------------------
        // The convention is varchar + PHP backed enum + a check constraint; this
        // migration originally shipped none, so any string could be written to a
        // status column and would only surface later as an enum cast failure.
        //
        // Constrained here only where the enum actually exists. employees
        // (contract_type, salary_type, status) and payroll_items.status are
        // documented in docs/07-enums.md but have no enum class yet — inventing
        // a value set for them here would guess at a contract that has not been
        // written. employee_advances and commissions were added the same way
        // once AdvanceStatus and CommissionStatus landed.
        DB::statement("ALTER TABLE payments ADD CHECK (method IN ('".implode("','", PaymentMethod::values())."'))");
        DB::statement("ALTER TABLE payments ADD CHECK (status IN ('".implode("','", PaymentStatus::values())."'))");
        DB::statement("ALTER TABLE payments ADD CHECK (direction IN ('inbound','outbound'))");
        DB::statement("ALTER TABLE payment_schedules ADD CHECK (status IN ('".implode("','", InstallmentStatus::values())."'))");
        DB::statement("ALTER TABLE deposits ADD CHECK (method IN ('".implode("','", PaymentMethod::values())."'))");
        DB::statement("ALTER TABLE deposits ADD CHECK (status IN ('".implode("','", DepositStatus::values())."'))");
        DB::statement("ALTER TABLE deposit_deductions ADD CHECK (reason IN ('".implode("','", DeductionReason::values())."'))");
        DB::statement("ALTER TABLE fines ADD CHECK (type IN ('".implode("','", FineType::values())."'))");
        DB::statement("ALTER TABLE fines ADD CHECK (liability IN ('".implode("','", FineLiability::values())."'))");
        DB::statement("ALTER TABLE fines ADD CHECK (status IN ('".implode("','", FineStatus::values())."'))");
        DB::statement("ALTER TABLE owner_installments ADD CHECK (status IN ('".implode("','", InstallmentStatus::values())."'))");
        // This constraint landed after the migration shipped with the rest of
        // the phase: it applies on fresh migrations only — an existing dev DB
        // needs `migrate:fresh` for it to actually exist.
        DB::statement("ALTER TABLE employee_advances ADD CHECK (status IN ('".implode("','", AdvanceStatus::values())."'))");
        DB::statement("ALTER TABLE commissions ADD CHECK (status IN ('".implode("','", CommissionStatus::values())."'))");
        DB::statement("ALTER TABLE payroll_runs ADD CHECK (status IN ('".implode("','", PayrollStatus::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('fines');
        Schema::dropIfExists('owner_installments');
        Schema::dropIfExists('deposit_deductions');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('payments');
    }
};
