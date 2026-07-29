<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CustomerGender;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Filament\Admin\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Admin\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Admin\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Admin\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('type')
                    ->options(CustomerType::options())
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set) => $set('company_name', null)),
                TextInput::make('first_name')
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->maxLength(255),
                DatePicker::make('date_of_birth'),
                TextInput::make('place_of_birth')
                    ->maxLength(255),
                TextInput::make('nationality')
                    ->maxLength(100),
                Select::make('gender')
                    ->options(CustomerGender::options()),
                TextInput::make('national_id')
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('company_name')
                    ->maxLength(255)
                    ->visible(fn (callable $get) => $get('type') === 'company'),
                TextInput::make('trade_register')
                    ->maxLength(50),
                TextInput::make('article_number')
                    ->maxLength(50),
                TextInput::make('driving_license_number')
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('license_category')
                    ->maxLength(10),
                DatePicker::make('license_issue_date'),
                DatePicker::make('license_expiry_date'),
                TextInput::make('license_issued_at')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('phone_secondary')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('whatsapp')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Textarea::make('address')
                    ->maxLength(65535),
                TextInput::make('city')
                    ->maxLength(255),
                TextInput::make('wilaya')
                    ->maxLength(100),
                TextInput::make('country')
                    ->maxLength(100)
                    ->default('Algeria'),
                Select::make('source')
                    ->options(CustomerSource::options()),
                TextInput::make('rating')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(5),
                Toggle::make('is_blacklisted')
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set, $state) => $state ? null : $set('blacklist_reason', null)),
                Textarea::make('blacklist_reason')
                    ->visible(fn (callable $get) => $get('is_blacklisted')),
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
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
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
                TextColumn::make('national_id')
                    ->searchable()
                    ->label('NIN'),
                IconColumn::make('is_blacklisted')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                Action::make('toggle_blacklist')
                    ->label(fn (Customer $record): string => $record->is_blacklisted ? 'Remove Blacklist' : 'Blacklist Customer')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (Customer $record): string => $record->is_blacklisted ? 'success' : 'danger')
                    ->form(fn (Customer $record): array => $record->is_blacklisted ? [] : [
                        Textarea::make('blacklist_reason')
                            ->label('Blacklist Reason')
                            ->required(),
                    ])
                    ->action(function (Customer $record, array $data): void {
                        $isBlacklisted = ! $record->is_blacklisted;
                        $record->update([
                            'is_blacklisted' => $isBlacklisted,
                            'blacklist_reason' => $isBlacklisted ? ($data['blacklist_reason'] ?? null) : null,
                            'blacklisted_at' => $isBlacklisted ? now() : null,
                        ]);

                        Notification::make()
                            ->success()
                            ->title($isBlacklisted ? 'Customer blacklisted' : 'Customer removed from blacklist')
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
            CustomerResource\RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
