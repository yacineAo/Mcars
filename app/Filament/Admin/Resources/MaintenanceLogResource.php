<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\CreateMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\EditMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\ListMaintenanceLogs;
use App\Models\MaintenanceLog;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceLogResource extends Resource
{
    protected static ?string $model = MaintenanceLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable(),
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
                DatePicker::make('next_due_date'),
                TextInput::make('next_due_odometer')
                    ->numeric(),
                Textarea::make('description')
                    ->maxLength(65535),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Car'),
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
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceLogs::route('/'),
            'create' => CreateMaintenanceLog::route('/create'),
            'edit' => EditMaintenanceLog::route('/{record}/edit'),
        ];
    }
}
