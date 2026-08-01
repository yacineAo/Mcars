<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $booking_id
 * @property ConditionReportType $type
 * @property int|null $performed_by_id
 * @property int|null $odometer
 * @property FuelLevel|null $fuel_level
 * @property bool $is_clean
 * @property array<int|string, mixed>|null $damage_points
 * @property string|null $notes
 * @property Carbon|null $performed_at
 */
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

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    /**
     * True once the booking is completed — i.e. the moment the closeout charges were
     * (or will be) posted. After that the readings are frozen: amending the odometer
     * would silently rewrite the justification of a charge already in the ledger.
     * Only notes and photos may still be added; the readings never.
     */
    public function isFrozen(): bool
    {
        return $this->booking?->status === BookingStatus::Completed;
    }

    /**
     * The other inspection of the same rental: a check-in report's partner is the
     * booking's check-out report and vice versa. The pair is what a closeout charge
     * or a damage dispute is argued from — the out reading and the in reading side
     * by side. Null until the booking has both.
     */
    public function pairedReport(): ?self
    {
        $opposite = $this->type === ConditionReportType::Checkin
            ? ConditionReportType::Checkout
            : ConditionReportType::Checkin;

        /** @var self|null $paired */
        $paired = $this->booking?->conditionReports()
            ->where('type', $opposite)
            ->first();

        return $paired;
    }

    /**
     * True when the booking already holds another report. Once it does, this
     * report's direction and booking are locked: retyping check-in to check-out
     * (or re-pointing the evidence at another booking) would silently create two
     * reports of the same direction on a booking, and the closeout takes "the
     * latest check-in" — two of them make the charge basis ambiguous.
     */
    public function hasOtherReport(): bool
    {
        return $this->booking?->conditionReports()
            ->where('id', '!=', $this->id)
            ->exists() ?? false;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
        $this->addMediaCollection('customer_signature');
    }
}
