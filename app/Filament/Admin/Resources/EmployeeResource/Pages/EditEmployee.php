<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Models\Employee;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * The number is minted by EmployeeService on create and is immutable
     * afterwards — payroll runs, advances and commissions reference it. The
     * form shows it disabled, but a crafted payload can still send a value, so
     * the record's own number rides back unchanged.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Employee $record */
        $record = $this->record;

        $data['employee_number'] = $record->employee_number;

        return $data;
    }
}
