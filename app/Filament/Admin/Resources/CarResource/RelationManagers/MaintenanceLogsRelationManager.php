<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenanceLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceLogs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('vendor_id')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->nullable(),
                Select::make('type')
                    ->options(MaintenanceType::options())
                    ->required(),
                Select::make('status')
                    ->options(MaintenanceStatus::options())
                    ->required(),
                DatePicker::make('scheduled_for'),
                DatePicker::make('started_at'),
                DatePicker::make('completed_at'),
                TextInput::make('odometer_at_service')
                    ->numeric(),
                TextInput::make('cost_parts')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('cost_labour')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('total_cost')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('invoice_number')
                    ->maxLength(255),
                Textarea::make('description')
                    ->maxLength(65535),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->date()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->searchable(),
            ])
            ->filters([])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
