<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\CreateCarOwner;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\EditCarOwner;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\ListCarOwners;
use App\Models\CarOwner;
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
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CarOwnerResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarOwner::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('type')
                    ->options([
                        'individual' => 'Individual',
                        'company' => 'Company',
                    ])
                    ->required(),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->maxLength(255),
                TextInput::make('company_name')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(50),
                TextInput::make('whatsapp')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                TextInput::make('national_id')
                    ->maxLength(50),
                TextInput::make('trade_register')
                    ->maxLength(50),
                Textarea::make('address')
                    ->maxLength(65535),
                TextInput::make('wilaya')
                    ->maxLength(100),
                TextInput::make('bank_name')
                    ->maxLength(255),
                TextInput::make('bank_rib')
                    ->maxLength(50),
                TextInput::make('ccp_account')
                    ->maxLength(50),
                TextInput::make('baridimob_number')
                    ->maxLength(50),
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
                TextColumn::make('first_name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('last_name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('company_name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('cars_count')
                    ->counts('cars')
                    ->label('Cars'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                Action::make('create_agreement')
                    ->label('New Agreement')
                    ->icon('heroicon-o-document-plus')
                    ->color('primary')
                    ->visible(fn (CarOwner $record): bool => $record->is_active)
                    ->form([
                        Select::make('car_id')
                            ->relationship('cars', 'registration_number')
                            ->required(),
                        Select::make('model')
                            ->options([
                                'fixed_monthly' => 'Fixed Monthly',
                                'revenue_share' => 'Revenue Share',
                                'hybrid' => 'Hybrid',
                            ])
                            ->required(),
                        TextInput::make('monthly_rent_amount')
                            ->numeric()
                            ->prefix('DZD'),
                        TextInput::make('share_percentage')
                            ->numeric()
                            ->suffix('%'),
                        DatePicker::make('start_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('end_date'),
                    ])
                    ->action(function (CarOwner $record, array $data): void {
                        CarOwnershipAgreement::create([
                            'car_owner_id' => $record->id,
                            'car_id' => $data['car_id'],
                            'branch_id' => $record->branch_id,
                            'model' => $data['model'],
                            'monthly_rent_amount' => $data['monthly_rent_amount'] ?? 0,
                            'share_percentage' => $data['share_percentage'] ?? 0,
                            'start_date' => $data['start_date'],
                            'end_date' => $data['end_date'] ?? null,
                            'status' => 'active',
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Ownership agreement created')
                            ->send();
                    }),

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
            'index' => ListCarOwners::route('/'),
            'create' => CreateCarOwner::route('/create'),
            'edit' => EditCarOwner::route('/{record}/edit'),
        ];
    }
}
