<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarBlockResource\Pages;

use App\Filament\Admin\Resources\CarBlockResource;
use App\Filament\Admin\Resources\MaintenanceLogResource;
use App\Models\CarBlock;
use App\Models\MaintenanceLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCarBlock extends ViewRecord
{
    protected static string $resource = CarBlockResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('car.registration_number')
                    ->label(__('car_blocks.fields.car')),
                TextEntry::make('reason')
                    ->label(__('car_blocks.fields.reason'))
                    ->badge(),
                TextEntry::make('state')
                    ->label(__('car_blocks.columns.state'))
                    ->badge()
                    ->state(fn (CarBlock $record): string => match (true) {
                        $record->isActiveNow() => __('car_blocks.columns.state_active'),
                        $record->isUpcoming() => __('car_blocks.columns.state_upcoming'),
                        default => __('car_blocks.columns.state_ended'),
                    })
                    ->color(fn (CarBlock $record): string => match (true) {
                        $record->isActiveNow() => 'success',
                        $record->isUpcoming() => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('starts_at')
                    ->label(__('car_blocks.fields.starts_at'))
                    ->dateTime(),
                TextEntry::make('ends_at')
                    ->label(__('car_blocks.fields.ends_at'))
                    ->dateTime(),
                TextEntry::make('maintenance_log_id')
                    ->label(__('car_blocks.fields.maintenance_log'))
                    ->formatStateUsing(function (CarBlock $record): string {
                        $log = $record->maintenanceLog;

                        if (! $log instanceof MaintenanceLog) {
                            return '—';
                        }

                        return __(':type — :date', [
                            'type' => $log->type->getLabel(),
                            'date' => $log->scheduled_for?->format('d/m/Y') ?? '—',
                        ]);
                    })
                    ->url(function (CarBlock $record): ?string {
                        $log = $record->maintenanceLog;

                        return $log instanceof MaintenanceLog && MaintenanceLogResource::canAccess()
                            ? MaintenanceLogResource::getUrl('view', ['record' => $log])
                            : null;
                    }),
                TextEntry::make('branch.name')
                    ->label(__('Branch'))
                    ->placeholder('—')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
                TextEntry::make('notes')
                    ->label(__('car_blocks.fields.notes'))
                    ->placeholder('—')
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }
}
