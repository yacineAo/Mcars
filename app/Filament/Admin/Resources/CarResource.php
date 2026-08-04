<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgreementStatus;
use App\Enums\BlockReason;
use App\Enums\BodyType;
use App\Enums\CarStatus;
use App\Enums\FuelType;
use App\Enums\OwnershipType;
use App\Enums\TransmissionType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarResource\Pages\CreateCar;
use App\Filament\Admin\Resources\CarResource\Pages\EditCar;
use App\Filament\Admin\Resources\CarResource\Pages\ListCars;
use App\Filament\Admin\Resources\CarResource\Pages\ViewCar;
use App\Filament\Admin\Resources\CarResource\RelationManagers\AgreementsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\BlocksRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\BookingsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\FinesRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\MaintenanceLogsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\MaintenanceSchedulesRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\OwnerInstallmentsRelationManager;
use App\Models\Branch;
use App\Models\Car;
use App\Services\Booking\BlockCarService;
use App\Services\FleetStatusService;
use App\Services\ReportService;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
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
use Throwable;
use UnitEnum;

class CarResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Car::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
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
                        TextInput::make('chassis_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation, ?Car $record): bool => $operation === 'edit' && $record !== null && ($record->bookings()->exists() || $record->contracts()->exists())),
                        TextInput::make('engine_number')
                            ->maxLength(255),
                        TextInput::make('registration_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation, ?Car $record): bool => $operation === 'edit' && $record !== null && ($record->bookings()->exists() || $record->contracts()->exists())),
                        DatePicker::make('registration_date'),
                    ])
                    ->columns(3),
                Section::make('Classification')
                    ->schema([
                        Select::make('car_category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('ownership_type')
                            ->options(OwnershipType::options())
                            ->required()
                            ->live()
                            ->disabled(fn (string $operation, ?Car $record): bool => $operation === 'edit' && $record !== null && $record->agreements()->where('status', AgreementStatus::Active->value)->exists()),
                        // Hidden for a company-owned car, per the panel doc. Hiding alone
                        // cannot clear a stale value: a hidden component is skipped during
                        // dehydration, so switching third-party → company-owned would leave
                        // the old car_owner_id on the row. Car::booted() nulls it on save.
                        Select::make('car_owner_id')
                            ->relationship('owner', 'first_name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->hidden(fn (callable $get): bool => $get('ownership_type') !== OwnershipType::ThirdParty->value),
                    ])
                    ->columns(3),
                Section::make('Specification')
                    ->schema([
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
                    ])
                    ->columns(3),
                Section::make('Pricing')
                    ->schema([
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
                    ])
                    ->columns(3),
                Section::make('Acquisition')
                    ->schema([
                        DatePicker::make('purchase_date'),
                        TextInput::make('purchase_price')
                            ->numeric()
                            ->prefix('DZD'),
                        TextInput::make('current_value')
                            ->numeric()
                            ->prefix('DZD'),
                    ])
                    ->columns(3),
                Section::make('Telematics')
                    ->schema([
                        TextInput::make('gps_device_id')
                            ->maxLength(255),
                        TextInput::make('gps_provider')
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Photos')
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->collection('gallery')
                            ->multiple()
                            ->image()
                            ->maxFiles(20)
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('damage')
                            ->collection('damage')
                            ->multiple()
                            ->image()
                            ->maxFiles(10)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Status')
                    ->schema([
                        // A car cannot be born `rented`. `cars.status` is NOT NULL, so this
                        // stays `required()` — without it the placeholder option submits
                        // null and Postgres rejects the insert with a 23502.
                        Select::make('status')
                            ->options(fn (string $operation): array => $operation === 'create'
                                ? [
                                    CarStatus::Available->value => CarStatus::Available->getLabel(),
                                    CarStatus::OutOfService->value => CarStatus::OutOfService->getLabel(),
                                ]
                                : CarStatus::options())
                            ->default(CarStatus::Available->value)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->visible(fn (string $operation): bool => $operation !== 'edit'),
                        TextInput::make('odometer')
                            ->numeric()
                            ->suffix('km'),
                        Toggle::make('is_active')
                            ->default(true),
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Plate'),
                TextColumn::make('model')
                    ->label('Model')
                    ->searchable(['brand', 'model'])
                    ->formatStateUsing(fn (Car $record): string => trim($record->brand.' '.$record->model)),
                TextColumn::make('category.name')
                    ->sortable()
                    ->label('Category'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('daily_rate')
                    ->money('DZD')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('odometer')
                    ->sortable()
                    ->suffix(' km')
                    ->toggleable(),
                TextColumn::make('owner.first_name')
                    ->label('Owner')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->boolean()
                    ->toggleable(),
                // Month-to-date profit, per REQ-11. Resolved once for the whole page by
                // ListCars::monthToDateNetProfit() — a per-row report query cost four
                // queries per row. The figure comes from ReportService; nothing here sums
                // `transactions`.
                TextColumn::make('mtd_net_profit')
                    ->label('Profit (MTD)')
                    ->state(fn (Car $record, mixed $livewire): string => number_format(
                        $livewire instanceof ListCars ? $livewire->monthToDateNetProfit($record->id) : 0.0,
                        2,
                    ).' DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CarStatus::options()),
                SelectFilter::make('car_category_id')
                    ->relationship('category', 'name')
                    ->label('Category'),
                SelectFilter::make('ownership_type')
                    ->options(OwnershipType::options())
                    ->label('Ownership'),
                TernaryFilter::make('is_active')
                    ->default(true),
                // "Which cars are not road-legal today" — a fleet question, so it lives
                // here as well as on CarDocumentResource's renewals worklist (REQ-13).
                // Reads the four mirror columns maintained by CarDocumentObserver.
                SelectFilter::make('document_status')
                    ->label('Documents')
                    ->options([
                        'expired' => __('Expired'),
                        'expiring_30' => __('Expiring within 30 days'),
                        'missing' => __('Missing an expiry date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $today = CarbonImmutable::today();

                        return match ($value) {
                            'expired' => $query->where(fn (Builder $q) => $q
                                ->orWhere('insurance_expiry_date', '<', $today)
                                ->orWhere('technical_inspection_expiry_date', '<', $today)
                                ->orWhere('registration_expiry_date', '<', $today)
                                ->orWhere('road_tax_expiry_date', '<', $today)),
                            'expiring_30' => $query->where(fn (Builder $q) => $q
                                ->orWhereBetween('insurance_expiry_date', [$today, $today->addDays(30)])
                                ->orWhereBetween('technical_inspection_expiry_date', [$today, $today->addDays(30)])
                                ->orWhereBetween('registration_expiry_date', [$today, $today->addDays(30)])
                                ->orWhereBetween('road_tax_expiry_date', [$today, $today->addDays(30)])),
                            'missing' => $query->where(fn (Builder $q) => $q
                                ->orWhereNull('insurance_expiry_date')
                                ->orWhereNull('technical_inspection_expiry_date')
                                ->orWhereNull('registration_expiry_date')
                                ->orWhereNull('road_tax_expiry_date')),
                            default => $query,
                        };
                    }),
                // Only offered with branches.view_all. Without the permission the filter
                // is absent *and* getEloquentQuery() has already pinned the query, so a
                // hand-crafted Livewire payload naming another branch still returns nothing.
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->defaultSort('registration_number')
            ->recordActions([
                Action::make('book_now')
                    ->label(__('Book Now'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->url(fn (Car $record): string => BookingResource::getUrl('create', ['car_id' => $record->id]))
                    ->visible(fn (Car $record): bool => $record->status->is(CarStatus::Available, CarStatus::Reserved)),

                Action::make('update_status_odometer')
                    ->label(__('Update Status & Mileage'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->visible(fn (Car $record): bool => ! $record->status->is(CarStatus::Sold, CarStatus::ReturnedToOwner))
                    ->form([
                        Select::make('status')
                            ->options(fn (Car $record): array => collect(app(FleetStatusService::class)->allowedTransitions($record))
                                ->mapWithKeys(fn (string $s) => [$s => CarStatus::from($s)->getLabel()])
                                ->toArray())
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
                        'status' => $record->status->value,
                        'odometer' => $record->odometer,
                        'fuel_level' => $record->fuel_level,
                    ])
                    ->action(function (Car $record, array $data): void {
                        $newStatus = $data['status'];
                        assert(is_string($newStatus));

                        // FleetStatusService refuses an illegal transition, and refuses a
                        // terminal status while an active ownership agreement exists. Both
                        // are things a receptionist can legitimately attempt, so they are
                        // reported rather than allowed to surface as a 500.
                        try {
                            app(FleetStatusService::class)->transition($record, CarStatus::from($newStatus));
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Status not changed'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        $record->update([
                            'odometer' => $data['odometer'],
                            'odometer_updated_at' => now(),
                            'fuel_level' => $data['fuel_level'] ?? $record->fuel_level,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('Vehicle status & mileage updated'))
                            ->send();
                    }),

                Action::make('block_car')
                    ->label(__('Block Car'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn (Car $record): bool => $record->status->is(CarStatus::Available, CarStatus::Reserved))
                    ->form([
                        Select::make('reason')
                            ->options(BlockReason::options())
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->default(now())
                            ->required(),
                        DateTimePicker::make('ends_at')
                            ->required(),
                        Textarea::make('notes'),
                    ])
                    ->action(function (Car $record, array $data): void {
                        $reason = $data['reason'];
                        $startsAt = $data['starts_at'];
                        $endsAt = $data['ends_at'];
                        $notes = array_key_exists('notes', $data) && $data['notes'] !== null ? $data['notes'] : null;
                        assert(is_string($reason));
                        assert(is_string($startsAt));
                        assert(is_string($endsAt));
                        assert(is_string($notes) || $notes === null);

                        // A clashing window is expected input, not a programming error:
                        // BlockCarService rejects it, and so do the car_blocks EXCLUDE
                        // constraint and the block-vs-booking trigger underneath it.
                        try {
                            app(BlockCarService::class)->block(
                                $record,
                                BlockReason::from($reason),
                                Carbon::parse($startsAt),
                                Carbon::parse($endsAt),
                                $notes,
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Car not blocked'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Car blocked successfully'))
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
                // `bookings_exists` is selected once by getEloquentQuery(); calling
                // $record->bookings()->exists() here would be one query per row.
                DeleteAction::make()
                    ->hidden(fn (Car $record): bool => (bool) $record->getAttribute('bookings_exists')),
                // The change trail for this record, pre-filtered to it (ADV-03).
                Action::make('view_history')
                    ->label(__('activity_log.resource.actions.view_history'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(fn (Car $record): string => ActivityLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => Car::class],
                            'subject_id' => ['subject_id' => $record->getKey()],
                        ],
                    ], panel: 'admin'))
                    ->visible(fn (): bool => (bool) (Auth::user()?->can('audit.view') ?? false)),
            ]);
    }

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
     * A user without `branches.view_all` is pinned to their own accessible branches
     * server-side, regardless of any filter they submit. `withExists` keeps the row
     * Delete guard off the per-row query path.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['category', 'owner'])
            ->withExists('bookings');

        $user = Auth::user();

        if ($user !== null && ! $user->can('branches.view_all')) {
            $ids = $user->accessibleBranchIds();

            if ($ids !== []) {
                $query->whereIn('branch_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            // Editable, on EditCar — see EditCar::getAllRelationManagers().
            DocumentsRelationManager::class,
            MaintenanceLogsRelationManager::class,
            MaintenanceSchedulesRelationManager::class,
            AgreementsRelationManager::class,
            // Read-only history, on ViewCar — see ViewCar::getAllRelationManagers().
            BookingsRelationManager::class,
            ContractsRelationManager::class,
            FinesRelationManager::class,
            BlocksRelationManager::class,
            OwnerInstallmentsRelationManager::class,
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
