<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\CreateCarOwnershipAgreement;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\EditCarOwnershipAgreement;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\ListCarOwnershipAgreements;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages\ViewCarOwnershipAgreement;
use App\Models\CarOwnershipAgreement;
use App\Services\OwnerAgreementService;
use App\Services\Payment\OwnerStatementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CarOwnershipAgreementResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarOwnershipAgreement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Fleet';

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

    /**
     * Common term/schedule fields shared between the resource form and relation managers.
     *
     * @return array<int, mixed>
     */
    public static function getTermFields(): array
    {
        return [
            Section::make('Terms')
                ->columns(2)
                ->schema([
                    Select::make('model')
                        ->options(AgreementModel::options())
                        ->required()
                        ->live()
                        ->disabled(fn (string $operation, ?CarOwnershipAgreement $record): bool => $operation === 'edit' && $record !== null && ($record->status === AgreementStatus::Active || $record->ownerInstallments()->exists())),
                    TextInput::make('monthly_rent_amount')
                        ->numeric()
                        ->prefix('DZD')
                        ->required(fn (callable $get): bool => in_array($get('model'), [AgreementModel::FixedMonthly->value, AgreementModel::Hybrid->value], true))
                        ->visible(fn (callable $get): bool => in_array($get('model'), [AgreementModel::FixedMonthly->value, AgreementModel::Hybrid->value], true)),
                    TextInput::make('share_percentage')
                        ->numeric()
                        ->suffix('%')
                        ->maxValue(100)
                        ->required(fn (callable $get): bool => in_array($get('model'), [AgreementModel::RevenueShare->value, AgreementModel::Hybrid->value], true))
                        ->visible(fn (callable $get): bool => in_array($get('model'), [AgreementModel::RevenueShare->value, AgreementModel::Hybrid->value], true)),
                    DatePicker::make('start_date')
                        ->required()
                        ->disabled(fn (string $operation, ?CarOwnershipAgreement $record): bool => $operation === 'edit' && $record !== null && ($record->status === AgreementStatus::Active || $record->ownerInstallments()->exists())),
                    DatePicker::make('end_date'),
                ]),
            Section::make('Schedule')
                ->columns(2)
                ->schema([
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
                ]),
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->maxLength(65535),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Parties')
                    ->columns(2)
                    ->schema([
                        Select::make('car_id')
                            ->relationship('car', 'registration_number')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation, ?CarOwnershipAgreement $record): bool => $operation === 'edit' && $record !== null && ($record->status === AgreementStatus::Active || $record->ownerInstallments()->exists())),
                        Select::make('car_owner_id')
                            ->relationship('carOwner', 'first_name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation, ?CarOwnershipAgreement $record): bool => $operation === 'edit' && $record !== null && ($record->status === AgreementStatus::Active || $record->ownerInstallments()->exists())),
                    ]),
                ...static::getTermFields(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->label('Car')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('carOwner.full_name')
                    ->label('Owner')
                    ->sortable()
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('model')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->date()
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—')
                    ->sortable(),
                TextColumn::make('monthly_rent_amount')
                    ->money('DZD')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('share_percentage')
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AgreementStatus::options())
                    ->default(AgreementStatus::Active->value),
                SelectFilter::make('model')
                    ->options(AgreementModel::options()),
                Filter::make('live_on')
                    ->form([
                        DatePicker::make('date')
                            ->label('Live on'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date'], fn (Builder $q, $date): Builder => $q
                            ->where('start_date', '<=', $date)
                            ->where(fn (Builder $q): Builder => $q
                                ->whereNull('end_date')
                                ->orWhere('end_date', '>=', $date),
                            ),
                        ))
                    ->indicateUsing(fn (array $data): ?string => $data['date'] ? 'Live on: '.$data['date'] : null),
                SelectFilter::make('car_owner_id')
                    ->label('Owner')
                    ->relationship('carOwner', 'first_name')
                    ->searchable(),
                SelectFilter::make('car_id')
                    ->label('Car')
                    ->relationship('car', 'registration_number')
                    ->searchable(),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (CarOwnershipAgreement $record): void {
                        app(OwnerAgreementService::class)->activate($record);

                        Notification::make()
                            ->success()
                            ->title('Agreement activated')
                            ->send();
                    })
                    ->visible(fn (CarOwnershipAgreement $record): bool => $record->status !== AgreementStatus::Active && Auth::user()?->can('fleet.manage')),

                Action::make('end')
                    ->label('End Agreement')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        DatePicker::make('end_date')
                            ->label('End date')
                            ->default(now()),
                    ])
                    ->action(function (CarOwnershipAgreement $record, array $data): void {
                        app(OwnerAgreementService::class)->end($record, $data['end_date'] ?? null, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title('Agreement ended')
                            ->send();
                    })
                    ->visible(fn (CarOwnershipAgreement $record): bool => $record->status === AgreementStatus::Active && Auth::user()?->can('fleet.manage')),

                Action::make('generate_installments')
                    ->label('Generate Instalments')
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Generate pending instalments for this agreement based on its schedule.')
                    ->action(function (CarOwnershipAgreement $record): void {
                        $count = app(OwnerStatementService::class)->generateForAgreement($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title($count > 0 ? "{$count} instalment(s) generated" : 'No new instalments to generate')
                            ->send();
                    })
                    ->visible(fn (CarOwnershipAgreement $record): bool => $record->status === AgreementStatus::Active && Auth::user()?->can('reports.view_financials')),

                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CarOwnershipAgreement $record): bool => $record->ownerInstallments()->exists()),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['car', 'carOwner']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarOwnershipAgreements::route('/'),
            'create' => CreateCarOwnershipAgreement::route('/create'),
            'view' => ViewCarOwnershipAgreement::route('/{record}'),
            'edit' => EditCarOwnershipAgreement::route('/{record}/edit'),
        ];
    }
}
