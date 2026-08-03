<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * See docs/resource/08-vendor.md — the "bank / RIB / CCP fields for paying
 * vendors" proposal. No `type` dimension on vendors maps to a payment method
 * the way FinancialAccountType does, so all three stay always-visible and
 * optional rather than conditional on a select.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('bank_account_number', 50)->nullable()->after('address');
            $table->string('rib', 50)->nullable()->after('bank_account_number');
            $table->string('ccp_number', 50)->nullable()->after('rib');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['bank_account_number', 'rib', 'ccp_number']);
        });
    }
};
