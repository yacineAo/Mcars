<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\CreateCarOwnershipAgreement;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\EditCarOwnershipAgreement;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\ListCarOwnershipAgreements;
use App\Models\CarOwnershipAgreement;
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

class CarOwnershipAgreementResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarOwnershipAgreement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable(),
                Select::make('car_owner_id')
                    ->relationship('carOwner', 'first_name')
                    ->required()
                    ->searchable(),
                Select::make('model')
                    ->options(AgreementModel::options())
                    ->required(),
                Select::make('status')
                    ->options(AgreementStatus::options())
                    ->required(),
                TextInput::make('monthly_rent_amount')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('share_percentage')
                    ->numeric()
                    ->suffix('%'),
                DatePicker::make('start_date')
                    ->required(),
                DatePicker::make('end_date'),
                TextInput::make('payment_day_of_month')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(28),
                TextInput::make('installments_count')
                    ->numeric(),
                DatePicker::make('first_due_date'),
                TextInput::make('grace_days')
                    ->numeric()
                    ->default(0),
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
                    ->searchable(),
                TextColumn::make('carOwner.first_name')
                    ->sortable()
                    ->searchable()
                    ->label('Owner'),
                TextColumn::make('model')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('monthly_rent_amount')
                    ->money('DZD')
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (CarOwnershipAgreement $record): void {
                        $record->update(['status' => AgreementStatus::Active]);

                        Notification::make()
                            ->success()
                            ->title('Agreement activated')
                            ->send();
                    })
                    ->visible(fn (CarOwnershipAgreement $record): bool => $record->status !== AgreementStatus::Active),

                Action::make('end')
                    ->label('End Agreement')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CarOwnershipAgreement $record): void {
                        $record->update([
                            'status' => AgreementStatus::Ended,
                            'end_date' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Agreement ended')
                            ->send();
                    })
                    ->visible(fn (CarOwnershipAgreement $record): bool => $record->status === AgreementStatus::Active),

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
            'index' => ListCarOwnershipAgreements::route('/'),
            'create' => CreateCarOwnershipAgreement::route('/create'),
            'edit' => EditCarOwnershipAgreement::route('/{record}/edit'),
        ];
    }
}
