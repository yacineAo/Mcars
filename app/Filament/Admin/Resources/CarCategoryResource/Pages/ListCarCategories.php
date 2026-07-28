<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarCategoryResource\Pages;

use App\Filament\Admin\Resources\CarCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarCategories extends ListRecords
{
    protected static string $resource = CarCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
