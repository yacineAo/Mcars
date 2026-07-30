<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Owns "log a maintenance service from a schedule". Guards against duplicate open logs and
 * sets the branch from the car, not from the authenticated user.
 */
class LogMaintenanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function log(
        MaintenanceSchedule $schedule,
        CarbonInterface $scheduledFor,
    ): MaintenanceLog {
        $carId = $schedule->car_id;

        if ($carId === null) {
            throw new RuntimeException('Cannot log a service for a category-level schedule.');
        }

        return $this->db->transaction(function () use ($schedule, $scheduledFor, $carId): MaintenanceLog {
            $existing = MaintenanceLog::query()
                ->where('car_id', $carId)
                ->where('type', $schedule->task_type)
                ->whereIn('status', [MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress])
                ->exists();

            if ($existing) {
                throw new RuntimeException(
                    'An open (scheduled or in progress) log already exists for this car and task type.',
                );
            }

            $car = $schedule->car;

            return MaintenanceLog::create([
                'car_id' => $carId,
                'branch_id' => $car?->branch_id,
                'type' => $schedule->task_type,
                'status' => MaintenanceStatus::Scheduled,
                'scheduled_for' => $scheduledFor,
            ]);
        });
    }
}
