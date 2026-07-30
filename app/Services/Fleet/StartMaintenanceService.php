<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceLog;
use App\Services\FleetStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Owns "a maintenance service was started". Everything that follows from that fact happens
 * here, in one transaction: the log's status, started_at, and the car's transition to
 * Maintenance so the availability search stops seeing it.
 */
class StartMaintenanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FleetStatusService $fleetStatus,
    ) {}

    public function start(MaintenanceLog $log): MaintenanceLog
    {
        if ($log->status !== MaintenanceStatus::Scheduled) {
            throw new RuntimeException("Maintenance log [{$log->id}] cannot be started; status is {$log->status->value}.");
        }

        return $this->db->transaction(function () use ($log): MaintenanceLog {
            $log->fill([
                'status' => MaintenanceStatus::InProgress,
                'started_at' => CarbonImmutable::now(),
            ]);
            $log->save();

            $car = $log->car;

            if ($car !== null && $car->status === CarStatus::Available) {
                $this->fleetStatus->transition($car, CarStatus::Maintenance);
            }

            return $log;
        });
    }
}
