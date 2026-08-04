<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Admin\Resources\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMaintenanceSchedule extends ViewRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;

    private function daysRemaining(MaintenanceSchedule $record): ?int
    {
        if ($record->next_due_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($record->next_due_at, false);
    }

    private function kmRemaining(MaintenanceSchedule $record): ?int
    {
        if ($record->next_due_odometer === null || $record->car === null || $record->car->odometer === null) {
            return null;
        }

        return $record->next_due_odometer - (int) $record->car->odometer;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Applies to'))
                    ->schema([
                        TextEntry::make('car.registration_number')
                            ->label('Car')
                            ->placeholder('—'),
                        TextEntry::make('carCategory.name')
                            ->label('Category')
                            ->placeholder('—'),
                        TextEntry::make('task_type')
                            ->badge(),
                        IconEntry::make('is_active')
                            ->boolean(),
                    ])
                    ->columns(4),
                Section::make(__('Interval'))
                    ->schema([
                        TextEntry::make('interval_km')
                            ->suffix(' km')
                            ->placeholder('—'),
                        TextEntry::make('interval_days')
                            ->suffix(' days')
                            ->placeholder('—'),
                        TextEntry::make('alert_km_before')
                            ->suffix(' km')
                            ->placeholder('—'),
                        TextEntry::make('alert_days_before')
                            ->suffix(' days')
                            ->placeholder('—'),
                    ])
                    ->columns(4),
                Section::make(__('Last done'))
                    ->schema([
                        TextEntry::make('last_done_at')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('last_done_odometer')
                            ->suffix(' km')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('Next due'))
                    ->schema([
                        TextEntry::make('next_due_at')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('days_remaining')
                            ->label('Days remaining')
                            ->state(fn (MaintenanceSchedule $record): ?int => $this->daysRemaining($record))
                            ->placeholder('—')
                            ->color(fn (?int $state): string => match (true) {
                                $state === null => 'gray',
                                $state < 0 => 'danger',
                                $state <= 7 => 'warning',
                                default => 'success',
                            }),
                        TextEntry::make('next_due_odometer')
                            ->suffix(' km')
                            ->placeholder('—'),
                        TextEntry::make('km_remaining')
                            ->label('Km remaining')
                            ->state(fn (MaintenanceSchedule $record): ?int => $this->kmRemaining($record))
                            ->placeholder('—')
                            ->color(fn (?int $state): string => match (true) {
                                $state === null => 'gray',
                                $state < 0 => 'danger',
                                $state <= 1000 => 'warning',
                                default => 'success',
                            }),
                    ])
                    ->columns(4),
            ]);
    }
}
