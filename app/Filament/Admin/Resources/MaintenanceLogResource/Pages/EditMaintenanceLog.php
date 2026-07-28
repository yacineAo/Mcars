<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\Pages;

use App\Filament\Admin\Resources\MaintenanceLogResource;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceLog extends EditRecord
{
    protected static string $resource = MaintenanceLogResource::class;
}
