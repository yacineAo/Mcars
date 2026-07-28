<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\InstallmentStatus;
use App\Models\AlertRule;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\OwnerInstallment;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;

/**
 * Owner instalments falling due inside the lead-time window.
 *
 * The alert goes to the office, not to the owner: car owners are records here, not
 * accounts. The owner's name rides in the payload so whoever picks it up knows who
 * to call.
 */
final class OwnerInstallmentDueDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::OwnerInstallmentDue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = OwnerInstallment::query()
            ->with(['carOwner', 'car'])
            ->whereIn('status', [
                InstallmentStatus::Pending->value,
                InstallmentStatus::PartiallyPaid->value,
                InstallmentStatus::Overdue->value,
            ])
            ->whereDate('due_date', '<=', $now->addDays($rule->days_before)->toDateString());

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $installment) {
            $dueDate = CarbonImmutable::parse((string) $installment->due_date);
            /** @var CarOwner|null $owner */
            $owner = $installment->carOwner;
            /** @var Car|null $car */
            $car = $installment->car;

            yield new AlertSubject(
                subject: $installment,
                branchId: $installment->branch_id,
                payload: [
                    'owner' => SubjectLabel::carOwner($owner),
                    'car' => SubjectLabel::car($car),
                    'amount' => $installment->amount_due,
                    'due_date' => $dueDate->translatedFormat('d/m/Y'),
                    'sequence' => $installment->sequence_number,
                ],
            );
        }
    }
}
