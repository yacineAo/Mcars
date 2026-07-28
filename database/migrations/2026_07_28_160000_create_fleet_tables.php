<?php

declare(strict_types=1);

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Enums\BodyType;
use App\Enums\CarDocumentType;
use App\Enums\CarStatus;
use App\Enums\FuelType;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\OwnershipType;
use App\Enums\TransmissionType;
use App\Enums\VendorType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('car_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('type'); // individual | company
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('trade_register')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('national_id')->nullable();
            $table->string('phone');
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('wilaya')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_rib')->nullable();
            $table->string('ccp_account')->nullable();
            $table->string('baridimob_number')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_owner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ownership_type');

            $table->string('brand');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->integer('year');
            $table->string('color')->nullable();
            $table->string('body_type')->nullable();
            $table->string('transmission')->nullable();
            $table->string('fuel_type')->nullable();
            $table->integer('seats')->nullable();
            $table->integer('doors')->nullable();
            $table->string('chassis_number')->unique();
            $table->string('engine_number')->nullable();
            $table->string('registration_number')->unique();
            $table->date('registration_date')->nullable();

            $table->string('status');
            $table->integer('odometer')->default(0);
            $table->timestamp('odometer_updated_at')->nullable();
            $table->string('fuel_level')->nullable();

            $table->decimal('daily_rate', 18, 2)->default(0);
            $table->decimal('weekly_rate', 18, 2)->nullable();
            $table->decimal('monthly_rate', 18, 2)->nullable();
            $table->decimal('security_deposit_amount', 18, 2)->nullable();
            $table->integer('mileage_limit_per_day')->nullable();
            $table->decimal('extra_km_price', 18, 2)->nullable();
            $table->decimal('late_hour_fee', 18, 2)->nullable();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 18, 2)->nullable();
            $table->decimal('current_value', 18, 2)->nullable();

            $table->date('insurance_expiry_date')->nullable();
            $table->date('technical_inspection_expiry_date')->nullable();
            $table->date('registration_expiry_date')->nullable();
            $table->date('road_tax_expiry_date')->nullable();

            $table->string('gps_device_id')->nullable();
            $table->string('gps_provider')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('car_ownership_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_owner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model');
            $table->decimal('monthly_rent_amount', 18, 2)->nullable();
            $table->decimal('share_percentage', 5, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('payment_day_of_month')->nullable();
            $table->integer('installments_count')->nullable();
            $table->date('first_due_date')->nullable();
            $table->integer('grace_days')->default(0);
            $table->string('status');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('car_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('number')->nullable();
            $table->string('issuer')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('cost', 18, 2)->nullable();
            $table->integer('reminder_days_before')->default(30);
            $table->foreignId('replaced_by_id')->nullable()->constrained('car_documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('car_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_type');
            $table->integer('interval_km')->nullable();
            $table->integer('interval_days')->nullable();
            $table->date('last_done_at')->nullable();
            $table->integer('last_done_odometer')->nullable();
            $table->date('next_due_at')->nullable();
            $table->integer('next_due_odometer')->nullable();
            $table->integer('alert_km_before')->default(0);
            $table->integer('alert_days_before')->default(7);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status');
            $table->date('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('odometer_at_service')->nullable();
            $table->decimal('cost_parts', 18, 2)->nullable();
            $table->decimal('cost_labour', 18, 2)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('next_due_date')->nullable();
            $table->integer('next_due_odometer')->nullable();
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->createEnumChecks();
        $this->createExcludeConstraint();
    }

    private function createEnumChecks(): void
    {
        DB::statement('ALTER TABLE car_categories ADD CHECK (sort_order >= 0)');
        DB::statement("ALTER TABLE car_owners ADD CHECK (type IN ('individual','company'))");
        DB::statement("ALTER TABLE vendors ADD CHECK (type IN ('".implode("','", VendorType::values())."'))");
        DB::statement("ALTER TABLE cars ADD CHECK (ownership_type IN ('".implode("','", OwnershipType::values())."'))");
        DB::statement("ALTER TABLE cars ADD CHECK (status IN ('".implode("','", CarStatus::values())."'))");
        DB::statement("ALTER TABLE cars ADD CHECK (body_type IN ('".implode("','", BodyType::values())."'))");
        DB::statement("ALTER TABLE cars ADD CHECK (transmission IN ('".implode("','", TransmissionType::values())."'))");
        DB::statement("ALTER TABLE cars ADD CHECK (fuel_type IN ('".implode("','", FuelType::values())."'))");
        DB::statement("ALTER TABLE car_ownership_agreements ADD CHECK (model IN ('".implode("','", AgreementModel::values())."'))");
        DB::statement("ALTER TABLE car_ownership_agreements ADD CHECK (status IN ('".implode("','", AgreementStatus::values())."'))");
        DB::statement("ALTER TABLE car_documents ADD CHECK (type IN ('".implode("','", CarDocumentType::values())."'))");
        DB::statement("ALTER TABLE maintenance_schedules ADD CHECK (task_type IN ('".implode("','", MaintenanceType::values())."'))");
        DB::statement("ALTER TABLE maintenance_logs ADD CHECK (type IN ('".implode("','", MaintenanceType::values())."'))");
        DB::statement("ALTER TABLE maintenance_logs ADD CHECK (status IN ('".implode("','", MaintenanceStatus::values())."'))");
    }

    private function createExcludeConstraint(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement("
            ALTER TABLE car_ownership_agreements
            ADD CONSTRAINT agreements_no_overlap
            EXCLUDE USING gist (
                car_id WITH =,
                daterange(start_date, end_date, '[)') WITH &&
            ) WHERE (status = 'active')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('car_documents');
        Schema::dropIfExists('car_ownership_agreements');
        Schema::dropIfExists('cars');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('car_owners');
        Schema::dropIfExists('car_categories');

        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
