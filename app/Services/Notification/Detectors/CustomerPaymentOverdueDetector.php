<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Models\Customer;
use App\Models\PaymentSchedule;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;

/**
 * Scheduled customer payments past their due date and still unsettled.
 *
 * `payment_schedules.status` is a plain varchar carrying the same vocabulary as
 * InstallmentStatus ('pending', 'paid', 'overdue', 'waived'); it has no enum cast
 * on the model, so the values are matched as strings here.
 */
final class CustomerPaymentOverdueDetector implements AlertDetector
{
    private const array UNSETTLED = ['pending', 'overdue', 'partially_paid'];

    public function type(): AlertType
    {
        return AlertType::CustomerPaymentOverdue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = PaymentSchedule::query()
            ->with('customer')
            ->whereIn('status', self::UNSETTLED)
            ->whereDate('due_date', '<', $now->toDateString());

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $schedule) {
            $dueDate = CarbonImmutable::parse((string) $schedule->due_date);
            /** @var Customer|null $customer */
            $customer = $schedule->customer;

            yield new AlertSubject(
                subject: $schedule,
                branchId: $schedule->branch_id,
                payload: [
                    'customer' => SubjectLabel::customer($customer),
                    'amount' => $schedule->amount,
                    'due_date' => $dueDate->translatedFormat('d/m/Y'),
                    'days_late' => (int) $dueDate->diffInDays($now),
                ],
                targetedUserIds: array_filter([$customer?->user_id]),
            );
        }
    }
}
