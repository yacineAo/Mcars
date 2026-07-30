<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\MaintenancePoster;
use App\Services\FleetStatusService;
use App\Services\MaintenanceSchedulerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Owns "a service was completed". Everything that follows from that one fact happens here,
 * in one transaction boundary: the cost sum, the log, the car's odometer and status, the
 * next-due recalculation, and the E41 ledger posting.
 *
 * The sum used to live in MaintenanceLogResource's action, which CLAUDE.md's layering table
 * forbids in as many words — a Filament page "never sums amounts". More importantly, the
 * posting was missing entirely, so a car's Expenses figure omitted every repair bill.
 */
class CompleteMaintenanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly MaintenancePoster $poster,
        private readonly FleetStatusService $fleetStatus,
        private readonly MaintenanceSchedulerService $schedules,
    ) {}

    /**
     * @param FinancialAccount|null $account Paid from this account now; null leaves the
     *                                       cost on credit against 2210 AP–Suppliers.
     */
    public function complete(
        MaintenanceLog $log,
        CarbonImmutable $completedAt,
        int $odometerAtService,
        Money $costParts,
        Money $costLabour,
        ?string $invoiceNumber,
        ?FinancialAccount $account,
        int $userId,
    ): MaintenanceLog {
        // Idempotency is the whole reason this guard exists: without it, clicking
        // "Complete Service" twice posts E41 twice, and the ledger is append-only, so the
        // second row can only be undone by a reversal.
        if ($log->status === MaintenanceStatus::Completed) {
            throw new RuntimeException("Maintenance log [{$log->id}] is already completed.");
        }

        if ($log->status === MaintenanceStatus::Cancelled) {
            throw new RuntimeException("Maintenance log [{$log->id}] was cancelled and cannot be completed.");
        }

        if ($odometerAtService < 0) {
            throw new RuntimeException('Odometer reading cannot be negative.');
        }

        $total = $costParts->plus($costLabour);

        return $this->db->transaction(function () use (
            $log,
            $completedAt,
            $odometerAtService,
            $costParts,
            $costLabour,
            $total,
            $invoiceNumber,
            $account,
            $userId,
        ): MaintenanceLog {
            $log->fill([
                'status' => MaintenanceStatus::Completed,
                'completed_at' => $completedAt,
                'odometer_at_service' => $odometerAtService,
                'cost_parts' => $costParts->toDecimal(),
                'cost_labour' => $costLabour->toDecimal(),
                'total_cost' => $total->toDecimal(),
                'invoice_number' => $invoiceNumber,
            ]);
            $log->save();

            $this->releaseCar($log, $odometerAtService);

            // `next_due_at` / `next_due_odometer` are derived from the last completed
            // service plus the interval, which is why they are on no form. MaintenanceSchedulerService
            // already owned that calculation and had no caller — this is its caller.
            $this->schedules->recomputeSchedule($log);

            // A zero-cost service — a warranty job, or work done in-house — is a real
            // event with no money in it. Posting a zero-amount row would fail
            // validateDraft() and would say nothing anyway.
            if ($total->isPositive()) {
                $this->postE41($log->refresh(), $account, $userId);
            }

            return $log;
        });
    }

    private function postE41(MaintenanceLog $log, ?FinancialAccount $account, int $userId): Transaction
    {
        return $this->accounting->post(
            $this->poster->postMaintenanceCompleted($log, $account, $userId),
        );
    }

    /**
     * A car in the workshop comes back available. Routed through FleetStatusService so the
     * transition table stays the single authority on what a car may become — this service
     * does not get its own private opinion about it.
     */
    private function releaseCar(MaintenanceLog $log, int $odometerAtService): void
    {
        $car = $log->car;

        if ($car === null) {
            return;
        }

        $car->odometer = max($car->odometer ?? 0, $odometerAtService);
        $car->odometer_updated_at = CarbonImmutable::now();
        $car->save();

        if ($car->status === CarStatus::Maintenance) {
            $this->fleetStatus->transition($car, CarStatus::Available);
        }
    }
}
