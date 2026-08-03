<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CustomerGender;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\Wilaya;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Admin\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Admin\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Admin\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CustomerResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Identity'))
                    ->schema([
                        TextInput::make('code')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('company_name')
                            ->maxLength(255)
                            ->visible(fn (callable $get) => $get('type') === 'company'),
                        Select::make('gender')
                            ->options(CustomerGender::options()),
                        DatePicker::make('date_of_birth'),
                        TextInput::make('place_of_birth')
                            ->maxLength(255),
                        TextInput::make('nationality')
                            ->maxLength(100),
                        TextInput::make('national_id')
                            ->label('NIN')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('type')
                            ->options(CustomerType::options())
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('company_name', null)),
                        TextInput::make('trade_register')
                            ->maxLength(50),
                        TextInput::make('article_number')
                            ->maxLength(50),
                    ])
                    ->columns(3),
                Section::make(__('Driving Licence'))
                    ->schema([
                        TextInput::make('driving_license_number')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('license_category')
                            ->maxLength(10),
                        DatePicker::make('license_issue_date'),
                        DatePicker::make('license_expiry_date'),
                        TextInput::make('license_issued_at')
                            ->maxLength(255),
                    ])
                    ->columns(3),
                Section::make(__('Contact'))
                    ->schema([
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
                        Select::make('wilaya')
                            ->options(Wilaya::options())
                            ->searchable(),
                        TextInput::make('country')
                            ->maxLength(100)
                            ->default('Algeria'),
                    ])
                    ->columns(3),
                Section::make(__('Commercial'))
                    ->schema([
                        Select::make('source')
                            ->options(CustomerSource::options()),
                        TextInput::make('rating')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5),
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->columns(2),
                Section::make(__('Status'))
                    ->schema([
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('full_name')
                    ->label(__('Name'))
                    ->searchable(['first_name', 'last_name'])
                    ->getStateUsing(fn (Customer $record): string => trim($record->first_name.' '.$record->last_name)),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('national_id')
                    ->label('NIN')
                    ->searchable(),
                TextColumn::make('company_name')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                IconColumn::make('is_blacklisted')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle'),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('license_expiry_date')
                    ->label(__('Licence'))
                    ->date()
                    ->icon(fn (mixed $state): string => match (true) {
                        $state === null => 'heroicon-o-question-mark-circle',
                        CarbonImmutable::parse($state)->isPast() => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-check-circle',
                    })
                    ->color(fn (mixed $state): string => match (true) {
                        $state === null => 'gray',
                        CarbonImmutable::parse($state)->isPast() => 'danger',
                        default => 'success',
                    }),
            ])
            ->filters([
                TernaryFilter::make('is_blacklisted'),
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('source')
                    ->options(CustomerSource::options()),
                SelectFilter::make('wilaya')
                    ->options(Wilaya::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('toggle_blacklist')
                    ->label(fn (Customer $record): string => $record->is_blacklisted ? __('Remove Blacklist') : __('Blacklist Customer'))
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (Customer $record): string => $record->is_blacklisted ? 'success' : 'danger')
                    ->visible(fn (): bool => Auth::user()?->can('fleet.manage') ?? false)
                    ->form(fn (Customer $record): array => $record->is_blacklisted ? [] : [
                        Textarea::make('blacklist_reason')
                            ->label(__('Blacklist Reason'))
                            ->required(),
                    ])
                    ->action(function (Customer $record, array $data): void {
                        app(CustomerService::class)->toggleBlacklist(
                            $record,
                            $data['blacklist_reason'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title($record->is_blacklisted ? __('Customer blacklisted') : __('Customer removed from blacklist'))
                            ->send();
                    }),
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (): bool => Auth::user()?->can('fleet.manage') ?? false),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            CustomerResource\RelationManagers\DocumentsRelationManager::class,
            CustomerResource\RelationManagers\BookingsRelationManager::class,
            CustomerResource\RelationManagers\ContractsRelationManager::class,
            CustomerResource\RelationManagers\PaymentsRelationManager::class,
            CustomerResource\RelationManagers\DepositsRelationManager::class,
            CustomerResource\RelationManagers\PaymentSchedulesRelationManager::class,
            CustomerResource\RelationManagers\FinesRelationManager::class,
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
