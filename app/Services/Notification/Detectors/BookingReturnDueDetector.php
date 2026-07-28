<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\BookingStatus;
use App\Models\AlertRule;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Active rentals whose return falls inside the lead-time window.
 *
 * The window is [now, now + days_before], so a rule with a one-day lead never
 * surfaces a booking due in three days.
 */
final class BookingReturnDueDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::BookingReturnDue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = Booking::query()
            ->with(['car', 'customer'])
            ->where('status', BookingStatus::Active->value)
            ->whereNotNull('expected_return_at')
            ->whereBetween('expected_return_at', [
                $now,
                $now->addDays($rule->days_before)->endOfDay(),
            ]);

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $booking) {
            // Booking's relations and date casts are untyped for static analysis;
            // pinning them here keeps the guesswork out of the payload below.
            /** @var Customer|null $customer */
            $customer = $booking->customer;
            /** @var Car|null $car */
            $car = $booking->car;
            /** @var CarbonInterface|null $dueAt */
            $dueAt = $booking->expected_return_at;

            yield new AlertSubject(
                subject: $booking,
                branchId: $booking->branch_id,
                payload: [
                    'reference' => $booking->reference,
                    'customer' => SubjectLabel::customer($customer),
                    'car' => SubjectLabel::car($car),
                    'due_at' => $dueAt?->translatedFormat('d/m/Y H:i') ?? '—',
                ],
                targetedUserIds: array_filter([$customer?->user_id]),
            );
        }
    }
}
