<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionResource\Pages;

use App\Filament\Admin\Resources\CommissionResource;
use App\Models\Commission;
use App\Services\Payment\CommissionService;
use DomainException;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditCommission extends EditRecord
{
    protected static string $resource = CommissionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // The form disables the money terms once paid; this re-asserts them
        // for a crafted payload — the commission and its E59 posting must never
        // disagree. Notes stay editable; everything else is frozen.
        $record = $this->getRecord();

        if ($record instanceof Commission && $record->payroll_item_id !== null) {
            $data['employee_id'] = $record->employee_id;
            $data['booking_id'] = $record->booking_id;
            $data['basis_amount'] = $record->basis_amount;
            $data['rate'] = $record->rate;
            $data['earned_on'] = $record->earned_on->format('Y-m-d');
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Commission) {
            return $record;
        }

        // The service owns the amount and the status: it recomputes basis ×
        // rate and refuses to touch a commission already swept into payroll.
        try {
            return app(CommissionService::class)->update($record, $data, (int) auth()->id());
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'data.employee_id' => $e->getMessage(),
            ]);
        }
    }
}
