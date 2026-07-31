<?php

declare(strict_types=1);

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `contract_templates.locale` is now cast to the App\Enums\Locale backed enum, so it
 * needs the check constraint every other enum-backed column in this schema has
 * (varchar + PHP backed enum + check — see CLAUDE.md, "Enums").
 *
 * Same reasoning as `users.locale`: without it the column takes any 5-character
 * string, and a row holding one makes the cast throw ValueError on *read* — which
 * takes down the templates list and the contract-generation path that selects from it.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'contract_templates_locale_check';

    public function up(): void
    {
        // Fold anything already out of range onto the column default rather than
        // letting the ALTER fail: a deploy that stops halfway on one bad row is worse
        // than a template whose language silently reverts.
        DB::table('contract_templates')
            ->whereNotIn('locale', Locale::values())
            ->update(['locale' => Locale::French->value]);

        DB::statement(
            'ALTER TABLE contract_templates ADD CONSTRAINT '.self::CONSTRAINT.
            " CHECK (locale IN ('".implode("','", Locale::values())."'))",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contract_templates DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
