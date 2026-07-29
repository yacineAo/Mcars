<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportResource\Pages;

use App\Filament\Admin\Resources\ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    public function getTitle(): string
    {
        return __('reports.resources.report.plural_label');
    }

    public function getSubheading(): ?string
    {
        return __('reports.resources.report.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('reports.actions.new'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
