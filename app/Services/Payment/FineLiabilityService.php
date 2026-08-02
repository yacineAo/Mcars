<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Models\Booking;
use App\Models\Fine;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\DatabaseManager;

/**
 * The liability decision for a fine: who pays, and the moment the business
 * commits. The system suggests from the car's occupancy at the offence time
 * (ADR-011); a human decides. Deciding posts E49 (customer) or E50 (company)
 * in the same transaction, so the row and the ledger never disagree.
 */
class FineLiabilityService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PaymentService $payments,
    ) {}

    /**
     * The booking that had the car at the offence instant, if any.
     *
     * This is the whole liability suggestion: a hit means someone was renting
     * the car, so the driver is likely the customer; a miss means the car was
     * the company's to answer for. Null `actual_return_at` means the rental
     * was still open, which is itself a hit.
     *
     * The booking's *current* status is deliberately not consulted. Fines
     * arrive weeks after the rental, by which time the booking has been
     * checked in and reads `completed`; "had the car at that moment" (ADR-011)
     * is the time window, not today's status. A booking that never started
     * has no `actual_pickup_at` and is excluded by the window itself.
     */
    public function matchActiveBooking(int $carId, DateTimeInterface $violationAt): ?Booking
    {
        return Booking::query()
            ->where('car_id', $carId)
            ->where('actual_pickup_at', '<=', $violationAt)
            ->where(function ($query) use ($violationAt): void {
                $query->whereNull('actual_return_at')
                    ->orWhere('actual_return_at', '>=', $violationAt);
            })
            ->first();
    }

    /**
     * Propose who pays: a booking covering the offence instant means the
     * customer was driving, so the proposal is `Customer` with the matched
     * booking, customer and contract pre-filled; a miss means the car was
     * the company's to answer for, so the proposal is `Company`.
     *
     * The word *proposal* is the whole point (ADR-011): nothing here is a
     * decision. The fine's `status` stays `pending_review`, an unsaved fine
     * is returned as-is (never persisted), and nothing reaches the ledger —
     * only `confirmLiability()` commits, and it posts E49/E50 in the same
     * transaction as the decision.
     */
    public function proposeLiability(Fine $fine): Fine
    {
        $booking = $this->matchActiveBooking((int) $fine->car_id, CarbonImmutable::parse($fine->violation_at));

        $attributes = [
            'booking_id' => $booking?->id,
            'customer_id' => $booking?->customer_id,
            'contract_id' => $booking?->contract?->id,
            'liability_note' => $booking !== null
                ? 'Suggested: customer — booking #'.$booking->id.' was active at the offence time.'
                : 'Suggested: company — no active booking on this car at the offence time.',
        ];

        if (! $fine->exists) {
            return $fine->fill($attributes + [
                'liability' => $booking !== null ? FineLiability::Customer : FineLiability::Company,
                'status' => FineStatus::PendingReview,
            ]);
        }

        $fine->update($attributes);

        return $fine->fresh();
    }

    /**
     * Decide and commit. A customer liability posts E49 (1120 / 2220), a
     * company liability posts E50 (5140 / 2220) — both within the same
     * transaction as the decision, so a crash cannot leave a decided row
     * without its ledger entry. A posted fine is immutable (the correction
     * path is a reversal, never an edit).
     */
    public function confirmLiability(Fine $fine, string|FineLiability $liability, int $userId, ?string $note = null): Fine
    {
        $liability = $liability instanceof FineLiability ? $liability : FineLiability::from($liability);

        return $this->db->transaction(function () use ($fine, $liability, $userId, $note): Fine {
            $fine = Fine::query()->lockForUpdate()->findOrFail($fine->id);

            if ($fine->isPostedToLedger()) {
                throw new DomainException('Liability is already posted; reverse the posting to change it.');
            }

            $fine->update([
                'liability' => $liability,
                'liability_determined_by_id' => $userId,
                'liability_determined_at' => now(),
                'liability_note' => $note ?? $fine->liability_note,
                'status' => $liability === FineLiability::Customer
                    ? FineStatus::AssignedToCustomer
                    : FineStatus::PaidByCompany,
            ]);

            $posting = match ($liability) {
                FineLiability::Customer => $this->payments->assignFine($fine, $userId),
                FineLiability::Company => $this->payments->absorbFine($fine, $userId),
                // Owner liability (E56) is a payable adjustment with its own
                // conditions; nothing posts here until that flow exists.
                default => null,
            };

            if ($posting === null || $posting->isEmpty()) {
                throw new DomainException('The liability posting failed to reach the ledger.');
            }

            return $fine->fresh();
        });
    }
}
