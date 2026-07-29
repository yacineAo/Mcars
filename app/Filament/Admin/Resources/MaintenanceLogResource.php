<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\CreateMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\EditMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\ListMaintenanceLogs;
use App\Models\MaintenanceLog;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceLogResource extends Resource
{
    use TranslatesModelLabel;

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
                Action::make('start_service')
                    ->label('Start Service')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (MaintenanceLog $record): void {
                        $record->update([
                            'status' => MaintenanceStatus::InProgress,
                            'started_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Maintenance service started')
                            ->send();
                    })
                    ->visible(fn (MaintenanceLog $record): bool => $record->status === MaintenanceStatus::Scheduled),

                Action::make('complete_service')
                    ->label('Complete Service')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->form([
                        DatePicker::make('completed_at')->default(now())->required(),
                        TextInput::make('odometer_at_service')->numeric()->required(),
                        TextInput::make('cost_parts')->numeric()->prefix('DZD')->default(0),
                        TextInput::make('cost_labour')->numeric()->prefix('DZD')->default(0),
                        TextInput::make('invoice_number')->maxLength(255),
                    ])
                    ->action(function (MaintenanceLog $record, array $data): void {
                        $parts = Money::of($data['cost_parts'] ?? '0');
                        $labour = Money::of($data['cost_labour'] ?? '0');
                        $total = $parts->plus($labour);

                        $record->update([
                            'status' => MaintenanceStatus::Completed,
                            'completed_at' => $data['completed_at'],
                            'odometer_at_service' => $data['odometer_at_service'],
                            'cost_parts' => $parts->toDecimal(),
                            'cost_labour' => $labour->toDecimal(),
                            'total_cost' => $total->toDecimal(),
                            'invoice_number' => $data['invoice_number'] ?? null,
                        ]);

                        if ($record->car) {
                            $record->car->update([
                                'odometer' => max($record->car->odometer ?? 0, (int) $data['odometer_at_service']),
                                'odometer_updated_at' => now(),
                                'status' => CarStatus::Available,
                            ]);
                        }

                        Notification::make()
                            ->success()
                            ->title('Maintenance service completed')
                            ->send();
                    })
                    ->visible(fn (MaintenanceLog $record): bool => $record->status !== MaintenanceStatus::Completed && $record->status !== MaintenanceStatus::Cancelled),

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
