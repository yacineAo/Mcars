<?php

declare(strict_types=1);

use App\Enums\CustomerDocumentType;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('type');

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('gender')->nullable();
            $table->string('national_id')->nullable();

            $table->string('company_name')->nullable();
            $table->string('trade_register')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('article_number')->nullable();

            $table->string('driving_license_number')->nullable();
            $table->string('license_category')->nullable();
            $table->date('license_issue_date')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->string('license_issued_at')->nullable();

            $table->string('phone');
            $table->string('phone_secondary')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('wilaya')->nullable();
            $table->string('country')->default('Algeria');

            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->string('source')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('national_id');
            $table->index('driving_license_number');
            $table->index('phone');
        });

        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE customers ADD CHECK (type IN ('".implode("','", CustomerType::values())."'))");
        DB::statement("ALTER TABLE customers ADD CHECK (gender IN ('male','female'))");
        DB::statement('ALTER TABLE customers ADD CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE customers ADD CHECK (source IN ('".implode("','", CustomerSource::values())."'))");
        DB::statement("ALTER TABLE customer_documents ADD CHECK (type IN ('".implode("','", CustomerDocumentType::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customers');
    }
};
