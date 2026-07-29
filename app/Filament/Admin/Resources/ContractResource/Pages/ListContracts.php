<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\Pages;

use App\Filament\Admin\Resources\ContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
