<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Admin\Resources\MaintenanceScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceSchedules extends ListRecords
{
    protected static string $resource = MaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => MaintenanceScheduleResource::canCreate()),
        ];
    }
}
