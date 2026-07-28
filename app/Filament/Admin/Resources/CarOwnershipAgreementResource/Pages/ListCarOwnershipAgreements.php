<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages;

use App\Filament\Admin\Resources\CarOwnershipAgreementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarOwnershipAgreements extends ListRecords
{
    protected static string $resource = CarOwnershipAgreementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
