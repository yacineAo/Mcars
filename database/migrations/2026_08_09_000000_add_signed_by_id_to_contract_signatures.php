<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who at the desk witnessed an in-person signature.
 *
 * `contract_signatures` records *whose* signature went on the document and how, but not
 * which staff member vouched for it. `ContractService::markSigned()` takes the acting
 * user precisely so the audit trail can answer "who witnessed this signature" — without
 * the column, that parameter was silently dropped (ADR-001: an audit trail that cannot
 * answer who did what is not an audit trail). Nullable: OTP signatures come from the
 * customer directly and have no staff witness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table): void {
            $table->foreignId('signed_by_id')
                ->nullable()
                ->after('contract_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signed_by_id');
        });
    }
};
