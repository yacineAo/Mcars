<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ConditionReport extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'booking_id',
        'type',
        'performed_at',
        'performed_by_id',
        'odometer',
        'fuel_level',
        'is_clean',
        'damage_points',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConditionReportType::class,
            'fuel_level' => FuelLevel::class,
            'performed_at' => 'datetime',
            'is_clean' => 'boolean',
            'damage_points' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
        $this->addMediaCollection('customer_signature');
    }
}
