<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BlockReason;
use App\Enums\BodyType;
use App\Enums\CarStatus;
use App\Enums\FuelType;
use App\Enums\OwnershipType;
use App\Enums\TransmissionType;
use App\Filament\Admin\Resources\CarResource\Pages\CreateCar;
use App\Filament\Admin\Resources\CarResource\Pages\EditCar;
use App\Filament\Admin\Resources\CarResource\Pages\ListCars;
use App\Filament\Admin\Resources\CarResource\Pages\ViewCar;
use App\Filament\Admin\Resources\CarResource\RelationManagers\AgreementsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\MaintenanceLogsRelationManager;
use App\Models\Car;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('status')
                    ->options(CarStatus::options())
                    ->required(),
                Select::make('ownership_type')
                    ->options(OwnershipType::options())
                    ->required(),
                Select::make('car_category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->nullable(),
                Select::make('car_owner_id')
                    ->relationship('owner', 'first_name')
                    ->searchable()
                    ->nullable(),
                TextInput::make('brand')
                    ->required()
                    ->maxLength(255),
                TextInput::make('model')
                    ->required()
                    ->maxLength(255),
                TextInput::make('trim')
                    ->maxLength(255),
                TextInput::make('year')
                    ->numeric()
                    ->required(),
                TextInput::make('color')
                    ->maxLength(50),
                Select::make('body_type')
                    ->options(BodyType::options())
                    ->nullable(),
                Select::make('transmission')
                    ->options(TransmissionType::options())
                    ->nullable(),
                Select::make('fuel_type')
                    ->options(FuelType::options())
                    ->nullable(),
                TextInput::make('seats')
                    ->numeric(),
                TextInput::make('doors')
                    ->numeric(),
                TextInput::make('chassis_number')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('engine_number')
                    ->maxLength(255),
                TextInput::make('registration_number')
                    ->required()
                    ->unique(ignoreRecord: true),
                DatePicker::make('registration_date'),
                TextInput::make('daily_rate')
                    ->numeric()
                    ->prefix('DZD')
                    ->required(),
                TextInput::make('weekly_rate')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('monthly_rate')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('security_deposit_amount')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('mileage_limit_per_day')
                    ->numeric()
                    ->suffix('km'),
                TextInput::make('extra_km_price')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('late_hour_fee')
                    ->numeric()
                    ->prefix('DZD'),
                DatePicker::make('purchase_date'),
                TextInput::make('purchase_price')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('current_value')
                    ->numeric()
                    ->prefix('DZD'),
                Textarea::make('notes')
                    ->maxLength(65535),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('model')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Plate'),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('daily_rate')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('odometer')
                    ->sortable()
                    ->suffix(' km'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                Action::make('book_now')
                    ->label('Book Now')
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->url(fn (Car $record): string => BookingResource::getUrl('create', ['car_id' => $record->id]))
                    ->visible(fn (Car $record): bool => $record->status->is(CarStatus::Available, CarStatus::Reserved))
                    ->openUrlInNewTab(),

                Action::make('update_status_odometer')
                    ->label('Update Status & Mileage')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->visible(fn (Car $record): bool => ! $record->status->is(CarStatus::Sold, CarStatus::ReturnedToOwner))
                    ->form([
                        Select::make('status')
                            ->options(CarStatus::options())
                            ->required(),
                        TextInput::make('odometer')
                            ->numeric()
                            ->suffix('km')
                            ->required(),
                        TextInput::make('fuel_level')
                            ->numeric()
                            ->suffix('%')
                            ->nullable(),
                    ])
                    ->fillForm(fn (Car $record): array => [
                        'status' => $record->status?->value ?? $record->status,
                        'odometer' => $record->odometer,
                        'fuel_level' => $record->fuel_level,
                    ])
                    ->action(function (Car $record, array $data): void {
                        $record->update([
                            'status' => $data['status'],
                            'odometer' => $data['odometer'],
                            'odometer_updated_at' => now(),
                            'fuel_level' => $data['fuel_level'] ?? $record->fuel_level,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Vehicle status & mileage updated')
                            ->send();
                    }),

                Action::make('block_car')
                    ->label('Block Car')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn (Car $record): bool => $record->status->is(CarStatus::Available, CarStatus::Reserved))
                    ->form([
                        Select::make('reason')
                            ->options(BlockReason::options())
                            ->required(),
                        DateTimePicker::make('starts_at')->default(now())->required(),
                        DateTimePicker::make('ends_at')->required(),
                        Textarea::make('notes'),
                    ])
                    ->action(function (Car $record, array $data): void {
                        $record->blocks()->create([
                            'branch_id' => $record->branch_id,
                            'reason' => $data['reason'],
                            'starts_at' => $data['starts_at'],
                            'ends_at' => $data['ends_at'],
                            'notes' => $data['notes'] ?? null,
                            'created_by_id' => Auth::id(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Car blocked successfully')
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AgreementsRelationManager::class,
            DocumentsRelationManager::class,
            MaintenanceLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCars::route('/'),
            'create' => CreateCar::route('/create'),
            'view' => ViewCar::route('/{record}'),
            'edit' => EditCar::route('/{record}/edit'),
        ];
    }
}
