<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\Pages;

use App\Filament\Admin\Resources\CarOwnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarOwners extends ListRecords
{
    protected static string $resource = CarOwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
