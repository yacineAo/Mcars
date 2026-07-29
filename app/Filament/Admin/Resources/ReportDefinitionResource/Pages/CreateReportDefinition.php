<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportDefinitionResource\Pages;

use App\Filament\Admin\Resources\ReportDefinitionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportDefinition extends CreateRecord
{
    protected static string $resource = ReportDefinitionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['parameters'] = $this->extractParameters($data);
        $data['user_id'] = auth()->id();

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    private function extractParameters(array $data): array
    {
        $params = [];

        foreach (['branch_id', 'customer_id', 'car_id', 'car_owner_id'] as $key) {
            $paramKey = 'param_'.$key;

            if (array_key_exists($paramKey, $data) && $data[$paramKey] !== null && $data[$paramKey] !== '') {
                $params[$key] = (int) $data[$paramKey];
            }
        }

        return $params;
    }
}
