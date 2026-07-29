<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity as SpatieLogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wrapper around Spatie's LogsActivity with Mcars defaults.
 *
 * Usage: `use App\Models\Concerns\LogsActivity;` on any model, then override
 * `getActivitylogOptions()` for custom attribute selection.
 */
trait LogsActivity
{
    use SpatieLogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            // `logAll()` serialises every attribute into activity_log.attribute_changes,
            // which ActivityLogResource then displays. Eloquent's $hidden does not apply —
            // it governs array/JSON serialisation, not Spatie — so `logAll()` alone put
            // bcrypt password hashes and 2FA secrets into a screen an admin can read.
            //
            // Excluding getHidden() rather than a literal list ties this to the #[Hidden]
            // attribute each model already declares, so a new secret column is covered the
            // moment it is hidden, without anyone remembering to update this trait.
            ->logExcept($this->getHidden())
            ->dontLogIfAttributesChangedOnly(['updated_at', 'updated_by_id'])
            ->logOnlyDirty();
    }
}
