<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportDefinitionResource\Pages;

use App\Filament\Admin\Resources\ReportDefinitionResource;
use Filament\Resources\Pages\EditRecord;

class EditReportDefinition extends EditRecord
{
    protected static string $resource = ReportDefinitionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $params = $this->record->parameters ?? [];

        foreach (['branch_id', 'customer_id', 'car_id', 'car_owner_id'] as $key) {
            if (isset($params[$key])) {
                $data['param_'.$key] = $params[$key];
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['parameters'] = $this->extractParameters($data);

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
