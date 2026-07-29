<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes secrets already written into `activity_log.attribute_changes`.
 *
 * `LogsActivity` used `logAll()` with no exclusions, so every User save serialised the
 * whole model — bcrypt password hash, remember token and 2FA secret included — into a
 * payload that ActivityLogResource renders on screen. The trait now excludes
 * `getHidden()`, but rows written before that are still in the table.
 *
 * The keys are removed rather than the rows: an activity row is an audit record of *who
 * changed what and when*, and deleting it to hide a value would destroy the trail the
 * table exists for. Only the offending keys go.
 */
return new class extends Migration
{
    public function up(): void
    {
        $secrets = (new User)->getHidden();

        if ($secrets === []) {
            return;
        }

        DB::table('activity_log')
            ->whereNotNull('attribute_changes')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($secrets): void {
                foreach ($rows as $row) {
                    $changes = json_decode((string) $row->attribute_changes, true);

                    if (! is_array($changes)) {
                        continue;
                    }

                    $scrubbed = false;

                    // Spatie nests as {"attributes": {...}, "old": {...}}.
                    foreach (['attributes', 'old'] as $group) {
                        if (! isset($changes[$group]) || ! is_array($changes[$group])) {
                            continue;
                        }

                        foreach ($secrets as $secret) {
                            if (array_key_exists($secret, $changes[$group])) {
                                unset($changes[$group][$secret]);
                                $scrubbed = true;
                            }
                        }
                    }

                    if ($scrubbed) {
                        DB::table('activity_log')
                            ->where('id', $row->id)
                            ->update(['attribute_changes' => json_encode($changes)]);
                    }
                }
            });
    }

    /**
     * Deliberately irreversible. Restoring a leaked password hash is not a rollback
     * anybody wants, and the original values are not recoverable from here anyway.
     */
    public function down(): void
    {
        //
    }
};
