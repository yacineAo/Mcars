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
 * Rentals still active past their expected return.
 *
 * Reactive, so days_before does not apply — the condition is already true. The
 * repeat interval is what stops this becoming an hourly nag.
 */
final class BookingOverdueDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::BookingOverdue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = Booking::query()
            ->with(['car', 'customer'])
            ->whereIn('status', [BookingStatus::Active->value, BookingStatus::Overdue->value])
            ->whereNotNull('expected_return_at')
            ->where('expected_return_at', '<', $now);

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $booking) {
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
                    'hours_late' => $dueAt !== null ? (int) $dueAt->diffInHours($now) : 0,
                ],
                targetedUserIds: array_filter([$customer?->user_id]),
            );
        }
    }
}
