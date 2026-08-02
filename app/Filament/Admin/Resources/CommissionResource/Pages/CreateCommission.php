<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionResource\Pages;

use App\Filament\Admin\Resources\CommissionResource;
use App\Models\Commission;
use App\Services\Payment\CommissionService;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCommission extends CreateRecord
{
    protected static string $resource = CommissionResource::class;

    protected function handleRecordCreation(array $data): Commission
    {
        // The form has no amount field on purpose: the service computes
        // basis × rate itself, so the stored figure always agrees with its own
        // basis and rate — the record is evidence, not a typed answer.
        try {
            return app(CommissionService::class)->create($data, (int) auth()->id());
        } catch (DomainException $e) {
            // The form already refuses self-granted commissions; this catches
            // the same refusal at write time (defence in depth) as a field
            // error instead of a 500.
            throw ValidationException::withMessages([
                'data.employee_id' => $e->getMessage(),
            ]);
        }
    }
}
