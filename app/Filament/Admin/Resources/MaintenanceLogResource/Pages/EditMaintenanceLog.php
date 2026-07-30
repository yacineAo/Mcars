<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\Pages;

use App\Enums\MaintenanceStatus;
use App\Filament\Admin\Resources\MaintenanceLogResource;
use App\Models\MaintenanceLog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceLog extends EditRecord
{
    protected static string $resource = MaintenanceLogResource::class;

    protected function beforeSave(): void
    {
        /** @var MaintenanceLog $record */
        $record = $this->record;

        if ($record->status === MaintenanceStatus::Cancelled) {
            Notification::make()
                ->danger()
                ->title(__('A cancelled maintenance log cannot be edited.'))
                ->send();

            $this->halt();
        }
    }
}
