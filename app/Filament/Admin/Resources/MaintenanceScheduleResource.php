<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages\CreateMaintenanceSchedule;
use App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages\EditMaintenanceSchedule;
use App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages\ListMaintenanceSchedules;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceScheduleResource extends Resource
{
    protected static ?string $model = MaintenanceSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->nullable(),
                Select::make('car_category_id')
                    ->relationship('carCategory', 'name')
                    ->searchable()
                    ->nullable(),
                Select::make('task_type')
                    ->options(MaintenanceType::options())
                    ->required(),
                TextInput::make('interval_km')
                    ->numeric()
                    ->suffix('km'),
                TextInput::make('interval_days')
                    ->numeric()
                    ->suffix('days'),
                DatePicker::make('last_done_at'),
                TextInput::make('last_done_odometer')
                    ->numeric()
                    ->suffix('km'),
                DatePicker::make('next_due_at'),
                TextInput::make('next_due_odometer')
                    ->numeric()
                    ->suffix('km'),
                TextInput::make('alert_km_before')
                    ->numeric()
                    ->default(0)
                    ->suffix('km'),
                TextInput::make('alert_days_before')
                    ->numeric()
                    ->default(7)
                    ->suffix('days'),
                Toggle::make('is_active')
                    ->default(true),
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
                TextColumn::make('task_type')
                    ->sortable(),
                TextColumn::make('interval_km')
                    ->suffix(' km')
                    ->sortable(),
                TextColumn::make('interval_days')
                    ->suffix(' days')
                    ->sortable(),
                TextColumn::make('next_due_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('next_due_odometer')
                    ->suffix(' km')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                Action::make('log_maintenance')
                    ->label('Log Service Now')
                    ->icon('heroicon-o-wrench')
                    ->color('primary')
                    ->form([
                        DatePicker::make('scheduled_for')->default(now())->required(),
                    ])
                    ->action(function (MaintenanceSchedule $record, array $data): void {
                        MaintenanceLog::create([
                            'car_id' => $record->car_id,
                            'type' => $record->task_type,
                            'status' => MaintenanceStatus::Scheduled,
                            'scheduled_for' => $data['scheduled_for'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Maintenance service logged')
                            ->send();
                    })
                    ->visible(fn (MaintenanceSchedule $record): bool => ! empty($record->car_id)),

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
            'index' => ListMaintenanceSchedules::route('/'),
            'create' => CreateMaintenanceSchedule::route('/create'),
            'edit' => EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
