<?php

declare(strict_types=1);

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignIdFor(Branch::class, 'branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->unique();
            $table->string('whatsapp')->nullable();
            $table->string('avatar')->nullable();
            $table->string('locale', 5)->default(app()->getLocale());
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'branch_id', 'phone', 'whatsapp', 'avatar', 'locale', 'is_active',
                'last_login_at', 'last_login_ip', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at', 'must_change_password',
            ]);
        });
    }
};
