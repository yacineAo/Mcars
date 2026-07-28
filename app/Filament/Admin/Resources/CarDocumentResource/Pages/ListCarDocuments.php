<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarDocumentResource\Pages;

use App\Filament\Admin\Resources\CarDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarDocuments extends ListRecords
{
    protected static string $resource = CarDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
