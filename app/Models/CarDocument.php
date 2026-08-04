<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarDocumentType;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasLedgerPostings;
use Carbon\CarbonInterface;
use Database\Factories\CarDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $car_id
 * @property CarDocumentType $type
 * @property string|null $number
 * @property string|null $issuer
 * @property CarbonInterface|null $issue_date
 * @property CarbonInterface|null $expiry_date
 * @property string|null $cost
 * @property int|null $reminder_days_before
 * @property int|null $replaced_by_id
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 */
class CarDocument extends Model implements HasMedia
{
    /** @use HasFactory<CarDocumentFactory> */
    use HasAuditColumns, HasFactory, HasLedgerPostings, InteractsWithMedia, SoftDeletes;

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

    /**
     * Select `posted_to_ledger` alongside the row, so a table can show "is this renewal in
     * the ledger?" in one query instead of one per rendered document. Excludes reversal rows
     * — a reversal carries the same `source_type`/`source_id` as the posting it undoes.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeWithPostedToLedger(Builder $query): Builder
    {
        return $query->withExists([
            'ledgerTransactions as posted_to_ledger' => fn (Builder $q): Builder => $q->where('is_reversal', false),
        ]);
    }

    /** @return BelongsTo<Car, $this> */
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
