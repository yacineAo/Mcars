<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OwnerInstallmentResource\Pages;

use App\Filament\Admin\Resources\OwnerInstallmentResource;
use App\Services\Payment\OwnerStatementService;
use Filament\Resources\Pages\CreateRecord;

class CreateOwnerInstallment extends CreateRecord
{
    protected static string $resource = OwnerInstallmentResource::class;

    /**
     * Manual creation is a correction path — the generator owns the normal one
     * (OwnerStatementService) — but a row still needs its document number:
     * next in the agreement's run, via the generator's own numbering rule so
     * the two paths cannot diverge.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sequence_number'] = app(OwnerStatementService::class)
            ->nextSequenceNumber((int) $data['car_ownership_agreement_id']);

        return $data;
    }
}
