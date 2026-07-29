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
            ->dontLogIfAttributesChangedOnly(['updated_at', 'updated_by_id'])
            ->logOnlyDirty();
    }
}
