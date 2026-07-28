<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ContractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // -----------------------------------------------------------------------
        // Extras catalogue
        // -----------------------------------------------------------------------
        Schema::create('extras', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('pricing_unit', 16);
            $table->decimal('unit_price', 18, 2);
            $table->foreignId('ledger_account_id')->constrained('chart_of_accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // -----------------------------------------------------------------------
        // Car blocks (non-booking unavailability)
        // -----------------------------------------------------------------------
        Schema::create('car_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 32);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignId('maintenance_log_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('car_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE car_blocks ADD CONSTRAINT car_blocks_ends_gt_starts
            CHECK (ends_at > starts_at)
        SQL);

        // EXCLUDE on car_blocks: no overlapping blocks for same car
        DB::statement(<<<'SQL'
            ALTER TABLE car_blocks ADD CONSTRAINT car_blocks_no_overlap
            EXCLUDE USING gist (
                car_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
        SQL);

        // -----------------------------------------------------------------------
        // Bookings
        // -----------------------------------------------------------------------
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default(BookingStatus::Draft->value);

            // Period
            $table->timestampTz('pickup_at');
            $table->timestampTz('expected_return_at');
            $table->timestampTz('actual_pickup_at')->nullable();
            $table->timestampTz('actual_return_at')->nullable();
            $table->foreignId('pickup_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('return_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('pickup_location')->nullable();
            $table->string('return_location')->nullable();

            // Pricing snapshot
            $table->decimal('daily_rate', 18, 2);
            $table->unsignedSmallInteger('days_count');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('extras_total', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->decimal('total_amount', 18, 2);
            $table->decimal('security_deposit_amount', 18, 2)->default(0);

            // Handover
            $table->unsignedInteger('odometer_out')->nullable();
            $table->unsignedInteger('odometer_in')->nullable();
            $table->string('fuel_level_out', 20)->nullable();
            $table->string('fuel_level_in', 20)->nullable();
            $table->boolean('with_driver')->default(false);
            $table->foreignId('driver_employee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('car_id');
            $table->index('customer_id');
            $table->index('status');
        });

        // EXCLUDE on bookings: no overlapping confirmed/active/overdue bookings for same car
        DB::statement(<<<'SQL'
            ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                car_id WITH =,
                tstzrange(pickup_at, expected_return_at, '[)') WITH &&
            ) WHERE (status IN ('confirmed', 'active', 'overdue'))
        SQL);

        // -----------------------------------------------------------------------
        // Booking extras (pivot)
        // -----------------------------------------------------------------------
        Schema::create('booking_extras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('total', 18, 2);

            $table->unique(['booking_id', 'extra_id']);
        });

        // -----------------------------------------------------------------------
        // Additional drivers
        // -----------------------------------------------------------------------
        Schema::create('additional_drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('national_id', 32)->nullable();
            $table->string('driving_license_number', 32)->nullable();
            $table->date('driving_license_expiry')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('booking_id');
        });

        // -----------------------------------------------------------------------
        // Condition reports (checkout/checkin)
        // -----------------------------------------------------------------------
        Schema::create('condition_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestampTz('performed_at');
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('odometer')->nullable();
            $table->string('fuel_level', 20)->nullable();
            $table->boolean('is_clean')->default(true);
            $table->jsonb('damage_points')->default('[]');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('booking_id');
        });

        // -----------------------------------------------------------------------
        // Contract templates
        // -----------------------------------------------------------------------
        Schema::create('contract_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('locale', 5)->default('fr');
            $table->text('body');
            $table->string('terms_version', 16)->default('1.0');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('locale');
        });

        // -----------------------------------------------------------------------
        // Contracts
        // -----------------------------------------------------------------------
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('contract_number', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->unique()->constrained();
            $table->foreignId('car_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('contract_template_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 24)->default(ContractStatus::Draft->value);
            $table->jsonb('content_snapshot');
            $table->string('terms_version', 16)->nullable();
            $table->string('insurance_type', 16)->nullable();
            $table->decimal('franchise_amount', 18, 2)->default(0);

            $table->timestampTz('generated_at')->nullable();
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('document_hash', 64)->nullable();

            $table->timestampTz('sent_at')->nullable();
            $table->string('sent_channel', 32)->nullable();
            $table->string('sent_to')->nullable();

            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_notes')->nullable();
            $table->boolean('has_damages')->default(false);

            $table->foreignId('parent_contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            $table->timestamps();

            $table->index('car_id');
            $table->index('customer_id');
            $table->index('status');
        });

        // -----------------------------------------------------------------------
        // Contract signatures
        // -----------------------------------------------------------------------
        Schema::create('contract_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();

            $table->string('signer_role', 32);
            $table->string('signer_type', 64)->nullable();
            $table->unsignedBigInteger('signer_id')->nullable();
            $table->string('signer_name_snapshot');

            $table->string('method', 32);

            // OTP fields
            $table->string('otp_code_hash')->nullable();
            $table->string('otp_sent_to')->nullable();
            $table->timestampTz('otp_sent_at')->nullable();
            $table->timestampTz('otp_verified_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);

            $table->string('document_hash', 64)->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('geolocation')->nullable();

            $table->timestamps();

            $table->index('contract_id');
            $table->index(['signer_type', 'signer_id']);
        });

        // -----------------------------------------------------------------------
        // Trigger: car_blocks vs bookings cross-check
        // -----------------------------------------------------------------------
        DB::statement("
            CREATE OR REPLACE FUNCTION check_car_block_booking_conflict()
            RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM bookings
                    WHERE car_id = NEW.car_id
                      AND status IN ('confirmed', 'active', 'overdue')
                      AND tstzrange(NEW.starts_at, NEW.ends_at, '[)') && tstzrange(pickup_at, expected_return_at, '[)')
                ) THEN
                    RAISE EXCEPTION 'Car is already booked during this period'
                      USING HINT = 'The car has active bookings that overlap with this block.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE OR REPLACE TRIGGER check_car_block_booking_conflict
                BEFORE INSERT OR UPDATE ON car_blocks
                FOR EACH ROW EXECUTE FUNCTION check_car_block_booking_conflict()
        ');

        // Trigger: reverse — booking cannot overlap an existing car_block
        DB::statement("
            CREATE OR REPLACE FUNCTION check_booking_car_block_conflict()
            RETURNS trigger AS \$\$
            BEGIN
                IF NEW.status IN ('confirmed', 'active', 'overdue') THEN
                    IF EXISTS (
                        SELECT 1 FROM car_blocks
                        WHERE car_id = NEW.car_id
                          AND tstzrange(NEW.pickup_at, NEW.expected_return_at, '[)') && tstzrange(starts_at, ends_at, '[)')
                    ) THEN
                        RAISE EXCEPTION 'Car is blocked during this period'
                          USING HINT = 'The car has a maintenance or other block overlapping with this booking.';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE OR REPLACE TRIGGER check_booking_car_block_conflict
                BEFORE INSERT OR UPDATE ON bookings
                FOR EACH ROW EXECUTE FUNCTION check_booking_car_block_conflict()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS check_booking_car_block_conflict ON bookings');
        DB::statement('DROP TRIGGER IF EXISTS check_car_block_booking_conflict ON car_blocks');
        DB::statement('DROP FUNCTION IF EXISTS check_booking_car_block_conflict');
        DB::statement('DROP FUNCTION IF EXISTS check_car_block_booking_conflict');

        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_templates');
        Schema::dropIfExists('condition_reports');
        Schema::dropIfExists('additional_drivers');
        Schema::dropIfExists('booking_extras');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('car_blocks');
        Schema::dropIfExists('extras');

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
        DB::statement('ALTER TABLE car_blocks DROP CONSTRAINT IF EXISTS car_blocks_no_overlap');
    }
};
