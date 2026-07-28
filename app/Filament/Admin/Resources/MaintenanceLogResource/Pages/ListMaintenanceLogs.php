<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\Pages;

use App\Filament\Admin\Resources\MaintenanceLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceLogs extends ListRecords
{
    protected static string $resource = MaintenanceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
