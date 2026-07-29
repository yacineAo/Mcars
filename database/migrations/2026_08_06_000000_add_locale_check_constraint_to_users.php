<?php

declare(strict_types=1);

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.locale` is now cast to the App\Enums\Locale backed enum, so it needs the
 * check constraint every other enum-backed column in this schema has (varchar + PHP
 * backed enum + check — see CLAUDE.md, "Enums").
 *
 * Without it the column accepts any 5-character string, and a row holding one makes
 * the cast throw ValueError on *read* — that user then cannot be loaded at all, which
 * locks them out and breaks every list they appear in.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'users_locale_check';

    public function up(): void
    {
        // Fold anything already out of range onto the default rather than letting the
        // ALTER fail: a deploy that stops halfway on one bad row is worse than a row
        // whose language silently reverts.
        DB::table('users')
            ->whereNotIn('locale', Locale::values())
            ->update(['locale' => Locale::French->value]);

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT '.self::CONSTRAINT.
            " CHECK (locale IN ('".implode("','", Locale::values())."'))",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
