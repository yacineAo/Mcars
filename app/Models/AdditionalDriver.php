<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalDriver extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'booking_id',
        'full_name',
        'national_id',
        'driving_license_number',
        'driving_license_expiry',
        'date_of_birth',
        'phone',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'driving_license_expiry' => 'date',
            'date_of_birth' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
