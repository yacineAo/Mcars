<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportDefinitionResource\Pages;

use App\Filament\Admin\Resources\ReportDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportDefinitions extends ListRecords
{
    protected static string $resource = ReportDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
