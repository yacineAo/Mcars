<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\Pages;

use App\Filament\Admin\Resources\MaintenanceLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceLog extends CreateRecord
{
    protected static string $resource = MaintenanceLogResource::class;
}
