<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages;

use App\Enums\AdvanceStatus;
use App\Filament\Admin\Resources\EmployeeAdvanceResource;
use App\Models\EmployeeAdvance;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeAdvance extends EditRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // The workflow owns the status and, once money moved, the terms. The
        // form disables them; this re-asserts them for a crafted payload — the
        // ledger posting (E61) and the advance row must never disagree.
        $record = $this->getRecord();

        if (! $record instanceof EmployeeAdvance) {
            return $data;
        }

        if ($record->status !== AdvanceStatus::Requested) {
            $data['employee_id'] = $record->employee_id;
            $data['amount'] = $record->amount;
            $data['advanced_on'] = $record->advanced_on->format('Y-m-d');
        }

        $data['status'] = $record->status->value;

        return $data;
    }
}
