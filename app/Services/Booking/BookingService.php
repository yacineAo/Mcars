<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

class BookingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly PricingService $pricing,
        private readonly BookingPoster $poster,
        private readonly BookingAvailabilityService $availability,
    ) {}

    public function createDraft(
        Car $car,
        Customer $customer,
        CarbonPeriod $period,
        User $createdBy,
        ?int $branchId = null,
    ): Booking {
        $days = (int) ceil($period->getStartDate()->diffInDays($period->getEndDate()));
        $subtotal = Money::of($car->daily_rate)->times($days)->toDecimal();

        return Booking::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'BK-'.strtoupper(Str::random(10)),
            'branch_id' => $branchId ?? $car->branch_id,
            'car_id' => $car->id,
            'customer_id' => $customer->id,
            'created_by_id' => $createdBy->id,
            'status' => BookingStatus::Draft,
            'pickup_at' => $period->getStartDate(),
            'expected_return_at' => $period->getEndDate(),
            'daily_rate' => $car->daily_rate,
            'days_count' => $days,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
        ]);
    }

    public function confirm(Booking $booking, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking): Booking {
            $booking->status = BookingStatus::Confirmed;
            $booking->save();

            return $booking->fresh();
        });
    }

    /**
     * Recompute `extras_total` from the booking's extras lines, and move the
     * difference into `total_amount`.
     *
     * The Extras relation manager is where an office builds the line items; without a
     * recompute the booking kept whatever the wizard typed and the ledger posted
     * (E04) while the lines showed something else. The lines are the source of truth,
     * so every create / edit / delete of a line funnels here, and the two columns are
     * updated together so `extras_total` and `total_amount` can never disagree.
     *
     * Refuses a booking that has started. `BookingExtra` blocks the line write before
     * it happens, so this is the second lock on the same door — but this is the door
     * itself: the rule is "a started rental's money columns are what the ledger says",
     * and it has to hold for every caller of this method, not only for line writes.
     */
    public function syncExtrasTotals(Booking $booking): Booking
    {
        if ($booking->hasStarted()) {
            throw new RuntimeException(
                'Extras totals cannot be recomputed once the rental has started — its revenue is already posted to the ledger.',
            );
        }

        $sum = Money::zero();

        foreach ($booking->extras()->pluck('total') as $lineTotal) {
            $sum = $sum->plus(Money::of((string) $lineTotal));
        }

        $delta = $sum->minus(Money::of($booking->extras_total));

        $booking->extras_total = $sum->toDecimal();
        $booking->total_amount = Money::of($booking->total_amount)->plus($delta)->toDecimal();
        // Not `saveQuietly()`: that suppressed HasAuditColumns and LogsActivity too, so
        // `total_amount` moved with no `updated_by_id` and no activity row. A money
        // column changing with nobody attached to it is exactly what the audit trail
        // exists to prevent. Booking has no save hook that writes extras, so no loop.
        $booking->save();

        return $booking;
    }

    /**
     * Price one extras line: quantity × unit price, in minor units.
     *
     * The line `total` used to be a free-text field the operator typed alongside the
     * quantity and the unit price, with nothing checking the three against each other —
     * and `total` is what feeds `extras_total`, `total_amount` and ultimately E04. A
     * fat-fingered figure was invoiced and posted exactly as typed.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public function priceExtraLine(array $line): array
    {
        $line['total'] = Money::of((string) ($line['unit_price'] ?? '0'))
            ->times(max((int) ($line['quantity'] ?? 1), 1))
            ->toDecimal();

        return $line;
    }

    /** @param array<string, mixed> $handoverData */
    public function checkOut(Booking $booking, array $handoverData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $handoverData, $by): Booking {
            $booking->status = BookingStatus::Active;
            $booking->actual_pickup_at = $handoverData['actual_pickup_at'] ?? now();
            $booking->odometer_out = $handoverData['odometer_out'] ?? null;
            $booking->fuel_level_out = $handoverData['fuel_level_out'] ?? null;
            $booking->save();

            // Post E02–E04 to the ledger
            $drafts = $this->poster->postRentalRevenue($booking, $by->id);
            $this->accounting->postMany(...$drafts);

            // Update car status
            $booking->car->update(['status' => CarStatus::Rented]);

            return $booking->fresh();
        });
    }

    /**
     * Extend a live rental to a later return date, and invoice the extra days.
     *
     * An extension is a repricing, not a date edit. Moving `expected_return_at` on the
     * edit form would hand the customer the additional days for nothing: revenue was
     * posted at pickup for the original contracted amount, and nothing recomputes it.
     *
     * The car must be free for the additional window — the `EXCLUDE` constraint would
     * reject an overlapping booking, but this booking already owns its own row, so the
     * constraint cannot see the conflict. Asking the availability service is what stops
     * a car being promised to two customers (ADR-002).
     *
     * The extra days are priced at the booking's own `daily_rate`, not today's list
     * price: the customer is extending the contract they signed.
     */
    public function extend(Booking $booking, DateTimeInterface $newReturnAt, User $by, ?string $reason = null): Booking
    {
        if (! $booking->status->is(BookingStatus::Active, BookingStatus::Overdue)) {
            throw new RuntimeException('Only a rental that is under way can be extended.');
        }

        $new = CarbonImmutable::parse($newReturnAt);
        $car = $booking->car;

        if ($car === null) {
            throw new RuntimeException('The booking has no car to extend.');
        }

        return $this->db->transaction(function () use ($booking, $car, $new, $by, $reason): Booking {
            // Re-read under a row lock and take the return date from *that* row, not
            // from the copy loaded before the transaction. Two offices extending the
            // same booking concurrently would otherwise both compute from the same
            // starting date, both pass every check, and both post E72 — the rental
            // extended once, the extra days invoiced twice.
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $current = $booking->expected_return_at;

            if ($current === null || $new->lessThanOrEqualTo($current)) {
                throw new RuntimeException('An extension must end after the current expected return.');
            }

            // The `EXCLUDE` constraint is what actually makes double-booking impossible
            // (ADR-002) and it covers this UPDATE — but it cannot produce a sentence a
            // receptionist can act on. This asks the same question first so the refusal
            // is legible; the constraint is still the thing standing behind it.
            if (! $this->availability->isAvailable($car, CarbonPeriod::create($current, $new), $booking)) {
                throw new RuntimeException('The car is already booked for part of the extension window.');
            }

            // Any started day is charged: a rental is priced in days, and returning the
            // car twelve hours late is a day the car could not be re-let. Recorded on
            // E72 in docs/05-accounting-model.md.
            $additionalDays = max((int) ceil($current->diffInDays($new, absolute: true)), 1);

            $additional = Money::of($booking->daily_rate)->times($additionalDays);

            $booking->expected_return_at = $new;
            $booking->days_count = (int) $booking->days_count + $additionalDays;
            $booking->subtotal = Money::of($booking->subtotal)->plus($additional)->toDecimal();
            $booking->total_amount = Money::of($booking->total_amount)->plus($additional)->toDecimal();
            $booking->save();

            // The reason rides on the ledger row rather than being appended to `notes`:
            // `notes` is a free-text field staff edit, so an audit trail kept there can
            // be typed over. The E72 row cannot be edited at all.
            $this->accounting->postMany(
                ...$this->poster->postExtensionRevenue(
                    $booking,
                    $additional->toDecimal(),
                    $by->id,
                    $additionalDays,
                    $reason,
                ),
            );

            return $booking->fresh();
        });
    }

    /** @param array<string, mixed> $returnData */
    public function checkIn(Booking $booking, array $returnData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $returnData): Booking {
            $booking->status = BookingStatus::Completed;
            $booking->actual_return_at = $returnData['actual_return_at'] ?? now();
            $booking->odometer_in = $returnData['odometer_in'] ?? null;
            $booking->fuel_level_in = $returnData['fuel_level_in'] ?? null;
            $booking->save();

            // Update car status
            $booking->car->update(['status' => CarStatus::Available]);

            return $booking->fresh();
        });
    }

    /** @param array<string, mixed> $returnData */
    public function checkInWithCharges(Booking $booking, array $returnData, User $by): Booking
    {
        return $this->db->transaction(function () use ($booking, $returnData, $by): Booking {
            $booking->status = BookingStatus::Completed;
            $booking->actual_return_at = $returnData['actual_return_at'] ?? now();
            $booking->odometer_in = $returnData['odometer_in'] ?? null;
            $booking->fuel_level_in = $returnData['fuel_level_in'] ?? null;
            $booking->save();

            $checkin = $booking->conditionReports()
                ->where('type', 'checkin')
                ->latest()
                ->first();

            if ($checkin) {
                $quote = $this->pricing->closeout($booking, $checkin);
                $drafts = $this->poster->postCloseoutCharges($booking, $quote, $by->id);
                if (! empty($drafts)) {
                    $this->accounting->postMany(...$drafts);
                }
            }

            $booking->car->update(['status' => CarStatus::Available]);

            return $booking->fresh();
        });
    }

    /**
     * Cancel a booking and unwind whatever revenue it has on the ledger.
     *
     * Cancelling a draft is a bare status flip. A booking that was handed over has
     * E02–E08 rows standing against it, and they cannot be edited or deleted — the
     * ledger is append-only — so each one is reversed (matrix E09), which also clears
     * the receivable so the customer stops owing for a rental that never finished.
     * Rows that an accountant already reversed (a correction) are skipped.
     */
    public function cancel(Booking $booking, string $reason, User $by): Booking
    {
        if ($booking->status->isTerminal()) {
            throw new RuntimeException('Cannot cancel a booking that is already '.$booking->status->value);
        }

        return $this->db->transaction(function () use ($booking, $reason, $by): Booking {
            $postings = $booking->ledgerTransactions()
                ->where('is_reversal', false)
                ->whereDoesntHave('reversal')
                ->orderByDesc('id')
                ->get();

            foreach ($postings as $posting) {
                $this->accounting->reverse($posting, $reason, $by);
            }

            $booking->status = BookingStatus::Cancelled;
            $booking->cancellation_reason = $reason;
            $booking->cancelled_at = now();
            $booking->cancelled_by_id = $by->id;
            $booking->save();

            return $booking->fresh();
        });
    }
}
