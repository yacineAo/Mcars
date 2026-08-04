<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerDocumentType;
use App\Models\Concerns\HasAuditColumns;
use Database\Factories\CustomerDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CustomerDocument extends Model implements HasMedia
{
    /** @use HasFactory<CustomerDocumentFactory> */
    use HasAuditColumns, HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'type',
        'number',
        'issue_date',
        'expiry_date',
        'verified_at',
        'verified_by_id',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'type' => CustomerDocumentType::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('front')
            ->useDisk('private')
            ->singleFile();
        $this->addMediaCollection('back')
            ->useDisk('private')
            ->singleFile();
    }
}
