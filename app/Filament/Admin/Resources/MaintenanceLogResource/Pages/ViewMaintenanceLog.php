<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\Pages;

use App\Filament\Admin\Resources\MaintenanceLogResource;
use App\Filament\Admin\Resources\MaintenanceLogResource\RelationManagers\TransactionsRelationManager;
use App\Models\MaintenanceLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewMaintenanceLog extends ViewRecord
{
    protected static string $resource = MaintenanceLogResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Job'))
                    ->schema([
                        TextEntry::make('car.registration_number')
                            ->label('Car'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('scheduled_for')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('Assignment'))
                    ->schema([
                        TextEntry::make('vendor.name')
                            ->placeholder('—'),
                        TextEntry::make('performedBy.name')
                            ->label('Performed by')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('Completion'))
                    ->visible(fn (MaintenanceLog $record): bool => $record->started_at !== null || $record->completed_at !== null)
                    ->schema([
                        TextEntry::make('started_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('odometer_at_service')
                            ->label('Odometer')
                            ->suffix(' km')
                            ->placeholder('—'),
                        TextEntry::make('invoice_number')
                            ->placeholder('—'),
                    ])
                    ->columns(4),
                Section::make(__('Cost'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('cost_parts')
                            ->money('DZD'),
                        TextEntry::make('cost_labour')
                            ->money('DZD'),
                        TextEntry::make('total_cost')
                            ->money('DZD')
                            ->weight('bold'),
                    ])
                    ->columns(3),
                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Ledger postings'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
