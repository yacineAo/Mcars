<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\ConditionReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Owns the invariants of a condition report.
 *
 * A condition report is evidence — the state of the car at handover and at return.
 * The only business rule a report has is that one booking holds at most one
 * inspection of each type: two check-in reports would make the closeout charge
 * basis ambiguous, because PricingService::closeout() takes "the latest check-in"
 * and a dispute would find a second, different one.
 *
 * The invariant is enforced twice: here (the readable refusal) and by the
 * `(booking_id, type)` unique index (the race-proof backstop — see
 * 2026_08_10_000000_add_condition_reports_guard_constraints.php). A concurrent
 * double-submit slips past the exists() check and lands on the index; that
 * violation surfaces as the same refusal, not a 500.
 *
 * The check-out/check-in flow will eventually write reports through this service;
 * today the resource pages do. Either way the guard lives here, not in a form
 * rule — every future writer gets it for free.
 */
class ConditionReportService
{
    /**
     * Create a report, refusing a second one of the same type for the booking.
     *
     * @param array{booking_id: int, type: string, performed_at: string, odometer?: int|null, fuel_level?: string|null, is_clean?: bool, damage_points?: array<int, mixed>|null, notes?: string|null} $data
     *
     * @throws RuntimeException when the booking already has a report of this type.
     */
    public function create(array $data, User $by): ConditionReport
    {
        $this->assertNoDuplicate($data['booking_id'], $data['type']);

        try {
            return ConditionReport::create([
                ...$data,
                'performed_by_id' => $by->id,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw new RuntimeException(__('condition_reports.errors.duplicate_type'));
        }
    }

    /**
     * Update a report, refusing any change that would leave the booking with two
     * reports of the same type — including re-pointing the evidence at a booking
     * that already holds one.
     *
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException when the change would create a duplicate type.
     */
    public function update(ConditionReport $report, array $data): ConditionReport
    {
        $bookingId = $data['booking_id'] ?? $report->booking_id;
        $type = $data['type'] ?? $report->type->value;

        $this->assertNoDuplicate($bookingId, $type, $report->id);

        try {
            $report->update($data);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw new RuntimeException(__('condition_reports.errors.duplicate_type'));
        }

        return $report;
    }

    private function assertNoDuplicate(int $bookingId, string $type, ?int $exceptId = null): void
    {
        $duplicate = ConditionReport::query()
            ->where('booking_id', $bookingId)
            ->where('type', $type)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($duplicate) {
            throw new RuntimeException(__('condition_reports.errors.duplicate_type'));
        }
    }
}
