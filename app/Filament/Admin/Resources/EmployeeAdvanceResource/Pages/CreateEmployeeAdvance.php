<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages;

use App\Enums\AdvanceStatus;
use App\Filament\Admin\Resources\EmployeeAdvanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeAdvance extends CreateRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The workflow owns the lifecycle: every advance starts as a request.
        // The form field is disabled, but a crafted payload can still carry a
        // status — re-assert it so an advance can never enter the ledger flow
        // already `recovered` or `outstanding` without ever being paid.
        $data['status'] = AdvanceStatus::Requested->value;

        return $data;
    }
}
