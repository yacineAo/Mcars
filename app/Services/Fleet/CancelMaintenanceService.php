<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceLog;
use App\Services\FleetStatusService;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Owns "a maintenance service was cancelled". The log is marked cancelled and the car
 * is transitioned back to Available if it was in Maintenance.
 */
class CancelMaintenanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FleetStatusService $fleetStatus,
    ) {}

    public function cancel(MaintenanceLog $log): MaintenanceLog
    {
        if ($log->status->is(MaintenanceStatus::Completed, MaintenanceStatus::Cancelled)) {
            throw new RuntimeException("Maintenance log [{$log->id}] cannot be cancelled; status is {$log->status->value}.");
        }

        return $this->db->transaction(function () use ($log): MaintenanceLog {
            $log->fill(['status' => MaintenanceStatus::Cancelled]);
            $log->save();

            $car = $log->car;

            if ($car !== null && $car->status === CarStatus::Maintenance) {
                $this->fleetStatus->transition($car, CarStatus::Available);
            }

            return $log;
        });
    }
}
