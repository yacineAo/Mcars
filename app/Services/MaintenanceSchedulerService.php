<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MaintenanceSchedulerService
{
    public function recomputeSchedule(MaintenanceLog $log): void
    {
        $schedules = MaintenanceSchedule::query()
            ->where('car_id', $log->car_id)
            ->where('task_type', $log->type->value)
            ->where('is_active', true)
            ->get();

        foreach ($schedules as $schedule) {
            $this->recomputeSingle($schedule, $log);
        }
    }

    private function recomputeSingle(MaintenanceSchedule $schedule, MaintenanceLog $log): void
    {
        $schedule->last_done_at = $log->completed_at?->toDateString();
        $schedule->last_done_odometer = $log->odometer_at_service;

        $nextDueAt = null;
        $nextDueOdometer = null;

        if ($schedule->interval_days && $log->completed_at) {
            $nextDueAt = Carbon::parse($log->completed_at)
                ->addDays($schedule->interval_days)
                ->toDateString();
        }

        if ($schedule->interval_km && $log->odometer_at_service) {
            $nextDueOdometer = $log->odometer_at_service + $schedule->interval_km;
        }

        $schedule->next_due_at = $nextDueAt;
        $schedule->next_due_odometer = $nextDueOdometer;
        $schedule->save();
    }

    /** @return Collection<int, MaintenanceSchedule> */
    public function dueSchedules(): Collection
    {
        return MaintenanceSchedule::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('next_due_at', '<=', now())
                    ->orWhere('next_due_odometer', '<=', \DB::raw('(SELECT odometer FROM cars WHERE cars.id = maintenance_schedules.car_id)'));
            })
            ->get();
    }
}
