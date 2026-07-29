<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractTemplateResource\Pages;

use App\Filament\Admin\Resources\ContractTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractTemplates extends ListRecords
{
    protected static string $resource = ContractTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
