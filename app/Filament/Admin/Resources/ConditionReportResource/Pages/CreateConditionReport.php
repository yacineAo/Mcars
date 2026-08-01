<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ConditionReportResource\Pages;

use App\Filament\Admin\Resources\ConditionReportResource;
use App\Models\ConditionReport;
use App\Services\Booking\ConditionReportService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateConditionReport extends CreateRecord
{
    protected static string $resource = ConditionReportResource::class;

    /**
     * Creation goes through ConditionReportService: it refuses a second report of
     * the same type for one booking, and stamps who performed the inspection. A
     * colleague may have typed the same check-in while this form was open — that
     * refusal is a field error, not a 500.
     */
    protected function handleRecordCreation(array $data): ConditionReport
    {
        try {
            return app(ConditionReportService::class)->create($data, Auth::user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'data.booking_id' => $e->getMessage(),
            ]);
        }
    }
}
