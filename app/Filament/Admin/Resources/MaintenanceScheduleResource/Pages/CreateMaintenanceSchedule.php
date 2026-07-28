<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Admin\Resources\MaintenanceScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceSchedule extends CreateRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;
}
