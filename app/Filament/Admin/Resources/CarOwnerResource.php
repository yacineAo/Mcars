<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Wilaya;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\CreateCarOwner;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\EditCarOwner;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\ListCarOwners;
use App\Filament\Admin\Resources\CarOwnerResource\Pages\ViewCarOwner;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\ChartOfAccount;
use App\Services\OwnerAgreementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class CarOwnerResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarOwner::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

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
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        Select::make('type')
                            ->options([
                                'individual' => 'Individual',
                                'company' => 'Company',
                            ])
                            ->live()
                            ->required()
                            ->disabled(fn (?CarOwner $record): bool => $record !== null && $record->exists && $record->agreements()->exists()),
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(255)
                            ->hidden(fn (callable $get): bool => $get('type') === 'company'),
                        TextInput::make('last_name')
                            ->maxLength(255)
                            ->hidden(fn (callable $get): bool => $get('type') === 'company'),
                        TextInput::make('company_name')
                            ->maxLength(255)
                            ->hidden(fn (callable $get): bool => $get('type') !== 'company'),
                        TextInput::make('trade_register')
                            ->maxLength(50)
                            ->hidden(fn (callable $get): bool => $get('type') !== 'company'),
                        TextInput::make('national_id')
                            ->label('NIN')
                            ->maxLength(50),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
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
                        Textarea::make('address')
                            ->maxLength(65535),
                        Select::make('wilaya')
                            ->options(Wilaya::options())
                            ->searchable(),
                    ])
                    ->columns(2),
                Section::make('Payment Details')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextInput::make('bank_name')
                            ->maxLength(255),
                        TextInput::make('bank_rib')
                            ->label('RIB')
                            ->maxLength(50),
                        TextInput::make('ccp_account')
                            ->label('CCP')
                            ->maxLength(50),
                        TextInput::make('baridimob_number')
                            ->label('BaridiMob')
                            ->maxLength(50),
                    ])
                    ->columns(2),
                Section::make('Status')
                    ->schema([
                        Textarea::make('notes')
                            ->maxLength(65535),
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $account2200Id = ChartOfAccount::where('code', '2200')->value('id');

                $query->withCount([
                    'agreements as active_agreements_count' => fn (Builder $q) => $q->where('status', 'active'),
                ]);

                if ($account2200Id !== null) {
                    $query->addSelect(['outstanding_balance' => function ($q) use ($account2200Id): void {
                        $q->selectRaw('COALESCE(SUM(CASE WHEN credit_account_id = ? THEN amount ELSE 0 END) - SUM(CASE WHEN debit_account_id = ? THEN amount ELSE 0 END), 0)', [$account2200Id, $account2200Id])
                            ->from('transactions')
                            ->whereColumn('transactions.car_owner_id', 'car_owners.id');
                    }]);
                } else {
                    $query->addSelect(DB::raw('CAST(0 AS numeric) as outstanding_balance'));
                }

                return $query;
            })
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('company_name')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('cars_count')
                    ->counts('cars')
                    ->label('Cars')
                    ->sortable(),
                TextColumn::make('active_agreements_count')
                    ->label('Active Agreements')
                    ->sortable(),
                TextColumn::make('outstanding_balance')
                    ->label('Balance')
                    ->money('DZD')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'individual' => 'Individual',
                        'company' => 'Company',
                    ]),
                TernaryFilter::make('is_active')
                    ->default(true),
                Filter::make('has_active_agreement')
                    ->label('Has Active Agreement')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('agreements', fn (Builder $q) => $q->where('status', 'active'))),
                SelectFilter::make('wilaya')
                    ->options(Wilaya::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('create_agreement')
                    ->label('New Agreement')
                    ->icon('heroicon-o-document-plus')
                    ->color('primary')
                    ->visible(fn (CarOwner $record): bool => $record->is_active)
                    ->form([
                        Select::make('car_id')
                            ->options(fn (): array => Car::whereDoesntHave('agreements', fn (Builder $q) => $q->where('status', 'active'))
                                ->pluck('registration_number', 'id')
                                ->toArray())
                            ->required()
                            ->searchable(),
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
                        TextInput::make('payment_day_of_month')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(28),
                        TextInput::make('installments_count')
                            ->numeric()
                            ->minValue(1),
                        DatePicker::make('first_due_date'),
                        TextInput::make('grace_days')
                            ->numeric()
                            ->default(0),
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->action(function (CarOwner $record, array $data): void {
                        app(OwnerAgreementService::class)->createAgreement($record, $data);

                        Notification::make()
                            ->success()
                            ->title('Ownership agreement created')
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CarOwner $record): bool => $record->cars_count > 0 || $record->agreements()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarOwners::route('/'),
            'create' => CreateCarOwner::route('/create'),
            'view' => ViewCarOwner::route('/{record}'),
            'edit' => EditCarOwner::route('/{record}/edit'),
        ];
    }
}
