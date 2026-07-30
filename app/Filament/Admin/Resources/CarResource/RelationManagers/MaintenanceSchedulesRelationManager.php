<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\MaintenanceType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Service intervals, REQ-12. Editable in place — intervals are exactly what a maintenance
 * officer maintains, which is why write access here is `fleet.manage_maintenance` and not
 * `fleet.manage`: a mechanic sets the oil-change interval without being able to re-price
 * the car.
 *
 * `next_due_at` / `next_due_odometer` are left off the form: they are derived from the last
 * completed service plus the interval, and are recomputed when a service completes.
 */
class MaintenanceSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceSchedules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Schedules');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    protected function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    protected function canDelete(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('task_type')
                    ->options(MaintenanceType::options())
                    ->required(),
                TextInput::make('interval_km')
                    ->numeric()
                    ->suffix('km')
                    ->minValue(1),
                TextInput::make('interval_days')
                    ->numeric()
                    ->suffix('days')
                    ->minValue(1),
                DatePicker::make('last_done_at'),
                TextInput::make('last_done_odometer')
                    ->numeric()
                    ->suffix('km'),
                TextInput::make('alert_km_before')
                    ->numeric()
                    ->suffix('km'),
                TextInput::make('alert_days_before')
                    ->numeric()
                    ->suffix('days'),
                Toggle::make('is_active')
                    ->default(true),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('task_type')
                    ->label('Task')
                    ->badge()
                    ->sortable(),
                TextColumn::make('interval_km')
                    ->label('Every')
                    ->suffix(' km')
                    ->placeholder('—'),
                TextColumn::make('interval_days')
                    ->label('Or every')
                    ->suffix(' days')
                    ->placeholder('—'),
                TextColumn::make('next_due_at')
                    ->label('Next due')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('next_due_odometer')
                    ->label('Next due at')
                    ->suffix(' km')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('task_type')
                    ->options(MaintenanceType::options()),
                TernaryFilter::make('is_active')
                    ->default(true),
            ])
            ->defaultSort('next_due_at')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
