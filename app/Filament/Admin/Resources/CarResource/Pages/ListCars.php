<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCars extends ListRecords
{
    protected static string $resource = CarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
