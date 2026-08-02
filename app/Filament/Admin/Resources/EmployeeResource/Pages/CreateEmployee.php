<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\Payment\EmployeeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * The number is minted by EmployeeService through SequenceGenerator inside
     * the same transaction as the insert — never typed on the form, and a
     * rolled-back insert takes its number back with it.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Employee $employee */
        $employee = app(EmployeeService::class)->create($data);

        return $employee;
    }
}
