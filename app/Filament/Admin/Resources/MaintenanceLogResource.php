<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\CreateMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\EditMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\ListMaintenanceLogs;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\ViewMaintenanceLog;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Services\Fleet\CancelMaintenanceService;
use App\Services\Fleet\CompleteMaintenanceService;
use App\Services\Fleet\StartMaintenanceService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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
use Throwable;
use UnitEnum;

class MaintenanceLogResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = MaintenanceLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function isFrozen(?MaintenanceLog $record): bool
    {
        return $record !== null && $record->status === MaintenanceStatus::Cancelled;
    }

    public static function form(Schema $schema): Schema
    {
        $frozen = fn (?MaintenanceLog $record): bool => self::isFrozen($record);

        return $schema
            ->schema([
                Section::make(__('Job'))
                    ->schema([
                        Select::make('car_id')
                            ->relationship('car', 'registration_number')
                            ->required()
                            ->searchable()
                            ->disabled(fn (?MaintenanceLog $record): bool => $record !== null && ($record->status->isTerminal())),
                        Select::make('type')
                            ->options(MaintenanceType::options())
                            ->required()
                            ->disabled(fn (?MaintenanceLog $record): bool => $record !== null && ($record->status->isTerminal())),
                        DatePicker::make('scheduled_for')
                            ->disabled(fn (?MaintenanceLog $record): bool => $record !== null && ($record->status->isTerminal())),
                        Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->disabled($frozen),
                    ])
                    ->columns(2),

                Section::make(__('Assignment'))
                    ->schema([
                        Select::make('vendor_id')
                            ->relationship('vendor', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                            ->searchable()
                            ->nullable()
                            ->disabled($frozen),
                        Select::make('performed_by_id')
                            ->label(__('Performed by'))
                            ->relationship('performedBy', 'name')
                            ->searchable()
                            ->nullable()
                            ->disabled($frozen),
                    ])
                    ->columns(2),

                Section::make(__('Completion'))
                    ->schema([
                        DateTimePicker::make('started_at')
                            ->disabled()
                            ->hiddenOn('create'),
                        DateTimePicker::make('completed_at')
                            ->disabled()
                            ->hiddenOn('create'),
                        TextInput::make('odometer_at_service')
                            ->numeric()
                            ->minValue(0)
                            ->disabled()
                            ->hiddenOn('create'),
                        TextInput::make('invoice_number')
                            ->maxLength(255)
                            ->disabled($frozen),
                    ])
                    ->columns(2)
                    ->hiddenOn('create'),

                Section::make(__('Cost'))
                    ->schema([
                        TextInput::make('cost_parts')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0)
                            ->disabled()
                            ->hiddenOn('create'),
                        TextInput::make('cost_labour')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0)
                            ->disabled()
                            ->hiddenOn('create'),
                        TextInput::make('total_cost')
                            ->numeric()
                            ->prefix('DZD')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Parts + labour, set when the service is completed.'))
                            ->hiddenOn('create'),
                    ])
                    ->columns(3)
                    ->hiddenOn('create'),

                Section::make(__('Notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->maxLength(65535)
                            ->disabled($frozen),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('car', 'vendor'))
            ->columns([
                TextColumn::make('car.registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Car'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('vendor.name')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('odometer_at_service')
                    ->label('Odometer')
                    ->suffix(' km')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->money('DZD')
                    ->sortable()
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MaintenanceStatus::options())
                    ->default([MaintenanceStatus::Scheduled->value, MaintenanceStatus::InProgress->value])
                    ->multiple(),
                Filter::make('overdue')
                    ->label(__('Overdue'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', MaintenanceStatus::Scheduled)
                        ->where('scheduled_for', '<=', now()->toDateString()))
                    ->toggle(),
                SelectFilter::make('type')
                    ->options(MaintenanceType::options()),
                Filter::make('completed_at')
                    ->form([
                        DatePicker::make('completed_from'),
                        DatePicker::make('completed_until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['completed_from'],
                            fn (Builder $q, $date): Builder => $q->whereDate('completed_at', '>=', $date),
                        )
                        ->when(
                            $data['completed_until'],
                            fn (Builder $q, $date): Builder => $q->whereDate('completed_at', '<=', $date),
                        )),
                SelectFilter::make('vendor_id')
                    ->label(__('Vendor'))
                    ->relationship('vendor', 'name')
                    ->searchable(),
                SelectFilter::make('car_id')
                    ->label(__('Car'))
                    ->relationship('car', 'registration_number')
                    ->searchable(),
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->defaultSort('scheduled_for', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('start_service')
                    ->label(__('Start Service'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceLog $record): bool => $record->status === MaintenanceStatus::Scheduled
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->action(function (MaintenanceLog $record): void {
                        try {
                            app(StartMaintenanceService::class)->start($record);
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Service not started'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Maintenance service started'))
                            ->send();
                    }),

                Action::make('complete_service')
                    ->label(__('Complete Service'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (MaintenanceLog $record): bool => ! $record->status->is(MaintenanceStatus::Completed, MaintenanceStatus::Cancelled)
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->form([
                        DateTimePicker::make('completed_at')
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_at_service')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('cost_parts')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0),
                        TextInput::make('cost_labour')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0),
                        TextInput::make('invoice_number')
                            ->maxLength(255),
                        Select::make('financial_account_id')
                            ->label(__('Paid from'))
                            ->helperText(__('Leave empty to record the bill as owed to the supplier.'))
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                    ])
                    ->action(function (MaintenanceLog $record, array $data): void {
                        $accountId = $data['financial_account_id'] ?? null;

                        try {
                            app(CompleteMaintenanceService::class)->complete(
                                log: $record,
                                completedAt: CarbonImmutable::parse((string) $data['completed_at']),
                                odometerAtService: (int) $data['odometer_at_service'],
                                costParts: Money::of($data['cost_parts'] ?? '0'),
                                costLabour: Money::of($data['cost_labour'] ?? '0'),
                                invoiceNumber: $data['invoice_number'] ?? null,
                                account: $accountId !== null ? FinancialAccount::find($accountId) : null,
                                userId: (int) Auth::id(),
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Service not completed'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Maintenance service completed'))
                            ->body(__('The cost was posted to the ledger against this car.'))
                            ->send();
                    }),

                Action::make('cancel_service')
                    ->label(__('Cancel Service'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceLog $record): bool => $record->status->is(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress)
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->action(function (MaintenanceLog $record): void {
                        try {
                            app(CancelMaintenanceService::class)->cancel($record);
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Service not cancelled'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Maintenance service cancelled'))
                            ->send();
                    }),

                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (MaintenanceLog $record): bool => (Auth::user()?->can('fleet.manage_maintenance') ?? false)
                        && ! $record->status->is(MaintenanceStatus::Cancelled)),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceLogs::route('/'),
            'create' => CreateMaintenanceLog::route('/create'),
            'view' => ViewMaintenanceLog::route('/{record}'),
            'edit' => EditMaintenanceLog::route('/{record}/edit'),
        ];
    }
}
