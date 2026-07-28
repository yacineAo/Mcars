<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarDocumentType;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CarDocument extends Model implements HasMedia
{
    use HasAuditColumns, HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'car_id',
        'type',
        'number',
        'issuer',
        'issue_date',
        'expiry_date',
        'cost',
        'reminder_days_before',
        'replaced_by_id',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'type' => CarDocumentType::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'cost' => 'decimal:2',
            'reminder_days_before' => 'integer',
        ];
    }

    /** @return BelongsTo<Car> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document')
            ->useDisk('private');
    }
}
