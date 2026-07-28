<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\MaintenanceType;
use App\Models\AlertRule;
use App\Models\Car;
use App\Models\MaintenanceSchedule;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Servicing due by date **or** by odometer, whichever comes first (REQ-17).
 *
 * Both limbs matter: a car that does 400 km a month hits the date first, a taxi
 * hits the kilometres first, and alerting on only one silently misses half the
 * fleet. Each schedule's own alert_days_before / alert_km_before act as a floor
 * under the rule's lead time, same as car documents.
 */
final class MaintenanceDueDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::MaintenanceDue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = MaintenanceSchedule::query()
            ->with('car')
            ->where('is_active', true)
            ->whereHas('car')
            ->where(function (Builder $outer) use ($rule, $now): void {
                $outer
                    ->where(fn ($q) => $q
                        ->whereNotNull('next_due_at')
                        ->whereRaw(
                            'next_due_at <= (?::date + make_interval(days => GREATEST(?, COALESCE(alert_days_before, 0))))',
                            [$now->toDateString(), $rule->days_before],
                        ))
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('next_due_odometer')
                        ->whereRaw(
                            'EXISTS (
                                SELECT 1 FROM cars
                                WHERE cars.id = maintenance_schedules.car_id
                                  AND cars.odometer >= maintenance_schedules.next_due_odometer
                                                       - COALESCE(maintenance_schedules.alert_km_before, 0)
                            )',
                        ));
            });

        if ($rule->branch_id !== null) {
            $query->whereHas('car', fn ($q) => $q->where('branch_id', $rule->branch_id));
        }

        foreach ($query->lazyById(100) as $schedule) {
            /** @var Car|null $car */
            $car = $schedule->car;
            /** @var CarbonInterface|null $nextDueAt */
            $nextDueAt = $schedule->next_due_at;
            /** @var MaintenanceType $taskType */
            $taskType = $schedule->task_type;

            $kmRemaining = $schedule->next_due_odometer !== null && $car?->odometer !== null
                ? $schedule->next_due_odometer - (int) $car->odometer
                : null;

            yield new AlertSubject(
                subject: $schedule,
                branchId: $car?->branch_id,
                payload: array_filter([
                    'car' => SubjectLabel::car($car),
                    'task' => $taskType->getLabel(),
                    'due_date' => $nextDueAt?->translatedFormat('d/m/Y'),
                    'due_odometer' => $schedule->next_due_odometer,
                    'current_odometer' => $car?->odometer,
                    'km_remaining' => $kmRemaining,
                ], static fn (mixed $v): bool => $v !== null),
            );
        }
    }
}
