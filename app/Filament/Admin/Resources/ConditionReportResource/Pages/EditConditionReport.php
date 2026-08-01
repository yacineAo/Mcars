<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ConditionReportResource\Pages;

use App\Filament\Admin\Resources\ConditionReportResource;
use App\Models\ConditionReport;
use App\Services\Booking\ConditionReportService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditConditionReport extends EditRecord
{
    protected static string $resource = ConditionReportResource::class;

    /**
     * Saves go through ConditionReportService, the same way creates do. The form
     * disables booking and direction once the booking holds a pair, but the
     * service is the guarantee — a save that would leave a booking with two
     * reports of the same type is a field error, not a 500.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ConditionReport $record */
        try {
            return app(ConditionReportService::class)->update($record, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'data.type' => $e->getMessage(),
            ]);
        }
    }
}
