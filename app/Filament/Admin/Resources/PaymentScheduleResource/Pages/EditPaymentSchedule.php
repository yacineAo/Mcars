<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\Pages;

use App\Filament\Admin\Resources\PaymentScheduleResource;
use App\Models\PaymentSchedule;
use App\Services\Payment\PaymentScheduleService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EditPaymentSchedule extends EditRecord
{
    protected static string $resource = PaymentScheduleResource::class;

    /**
     * Every field on this form but `due_date` is disabled, so editing an instalment
     * *is* rescheduling it — and rescheduling belongs to the service.
     *
     * Saving straight through Eloquent gave the record a second write path with
     * weaker rules than the `reschedule` action: no row lock, and a status check
     * that ran when the page was opened rather than when the row was written, so an
     * instalment settled in another tab could still be moved from this one.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PaymentSchedule $record */
        return app(PaymentScheduleService::class)->reschedule(
            $record,
            Carbon::parse((string) $data['due_date']),
        );
    }
}
