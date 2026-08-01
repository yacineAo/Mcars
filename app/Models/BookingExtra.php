<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Booking\BookingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class BookingExtra extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'extra_id',
        'quantity',
        'unit_price',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * The parent the pre-write guard loaded, handed forward to the post-write recompute.
     *
     * Deliberately *not* a general-purpose cache — that is the bug this file already had
     * once, when both hooks trusted `$line->booking` and read a copy loaded long before.
     * This is set moments earlier in the same save cycle and cleared the instant it is
     * used, so there is no window in which it can go stale. If the guard throws, the save
     * never reaches `saved` and the next attempt overwrites it.
     */
    private ?Booking $guardedParent = null;

    protected static function booted(): void
    {
        // Refused *before* the write, not after: a `saved` hook that threw would leave
        // the line row behind and only then complain. The relation manager hides the
        // buttons as well, but a UI that declines to offer an action is not the same
        // thing as the rule being enforced — an import, a console write or a second
        // screen reaches this table without passing that form.
        static::saving(fn (BookingExtra $line): null => self::assertBookingOpen($line));
        static::deleting(fn (BookingExtra $line): null => self::assertBookingOpen($line));

        // The parent's `extras_total` and `total_amount` are derived from the lines:
        // any create / edit / delete of a line must recompute them, or the booking
        // shows figures the ledger (E04) never saw. The math lives in the service.
        static::saved(fn (BookingExtra $line): ?Booking => self::syncParent($line));
        static::deleted(fn (BookingExtra $line): ?Booking => self::syncParent($line));
    }

    /**
     * Extras are frozen once the rental is under way.
     *
     * Past hand-over the booking's `extras_total` is what E04 posted, and the ledger is
     * append-only — a line added now would move `total_amount` and leave the revenue
     * rows behind, with the two disagreeing and no trace of why. Extras discovered
     * after hand-over belong on the closeout, which posts its own charges.
     */
    private static function assertBookingOpen(BookingExtra $line): null
    {
        $booking = self::parentBooking($line);
        $line->guardedParent = $booking;

        if ($booking !== null && $booking->hasStarted()) {
            throw new RuntimeException(
                'Extras cannot be changed once the rental has started — its revenue is already posted to the ledger.',
            );
        }

        return null;
    }

    private static function syncParent(BookingExtra $line): ?Booking
    {
        // Reuse the copy the pre-write guard just loaded, then drop it. Nothing between
        // the two hooks touches the booking — the write in the middle is this line, and
        // the recompute re-reads the lines from the database itself — so the second
        // load was a wasted query on every extras write.
        $booking = $line->guardedParent ?? self::parentBooking($line);
        $line->guardedParent = null;

        return $booking === null
            ? null
            : app(BookingService::class)->syncExtrasTotals($booking);
    }

    /**
     * The parent, read from the database rather than from the relation cache.
     *
     * `$line->booking` memoises the first load, so a line touched after its booking
     * changed would be judged against — and would recompute from — a stale copy. Both
     * callers here decide something about money on the strength of it: one refuses the
     * write if the rental has started, the other derives `total_amount` from the
     * booking's current figures.
     */
    private static function parentBooking(BookingExtra $line): ?Booking
    {
        return $line->booking()->first();
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }
}
