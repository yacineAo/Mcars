<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property int|null $branch_id
 */
class Activity extends SpatieActivity
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
