<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Admin\Resources\MaintenanceScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceSchedule extends CreateRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        validator($data, [
            'car_id' => 'required_without:car_category_id|integer|exists:cars,id',
            'car_category_id' => 'required_without:car_id|integer|exists:car_categories,id',
            'interval_km' => 'required_without:interval_days|integer|min:1',
            'interval_days' => 'required_without:interval_km|integer|min:1',
        ])->validate();

        $data['applies_to'] = null;

        return $data;
    }
}
