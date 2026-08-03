<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BookingStatus;
use App\Enums\FuelLevel;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\BookingResource\Pages;
use App\Filament\Admin\Resources\BookingResource\RelationManagers;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Rules\CustomerNotBlacklisted;
use App\Services\Booking\BookingService;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class BookingResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    /**
     * Reading a booking and working one are different permissions.
     *
     * `bookings.view` is the read gate the whole Bookings cluster shares (the accountant
     * audits rentals but never takes a car out). `bookings.operate` is the write gate for
     * the lifecycle — deliberately *not* `bookings.manage`, which governs the catalogue
     * (extras, contract templates: what a rental costs) and is manager-only. The
     * visibility matrix gives the receptionist and the supervisor "full" on Bookings, and
     * a receptionist who cannot check a car out cannot work the front desk.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('bookings.view') ?? false;
    }

    public static function canOperate(): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canOperate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canOperate();
    }

    /**
     * Only a draft can be deleted.
     *
     * Past checkout a booking has revenue, a receivable and possibly a deposit standing
     * behind it. The soft delete keeps the row, but hiding the booking while its ledger
     * entries stand leaves figures that reconcile to nothing — and the ledger is
     * append-only, so the entries cannot follow it. `cancel` is the operation for a
     * booking that has been invoiced; it posts the reversal (matrix E09).
     */
    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof Booking)) {
            return false;
        }

        return $record->status === BookingStatus::Draft && static::canOperate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    Step::make(__('Customer & Car'))
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('customer_id')
                                    ->label(__('Customer'))
                                    ->options(fn () => Customer::query()
                                        ->orderBy('first_name')
                                        ->get()
                                        ->mapWithKeys(fn (Customer $c) => [
                                            $c->id => $c->displayName().' — '.$c->phone,
                                        ])
                                        ->toArray())
                                    ->searchable()
                                    ->required()
                                    ->rule(fn (?Booking $record): CustomerNotBlacklisted => new CustomerNotBlacklisted(
                                        alreadyStarted: $record?->hasStarted() ?? false,
                                    ))
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                Select::make('car_id')
                                    ->relationship('car', 'registration_number')
                                    ->searchable()
                                    ->required()
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                            ]),
                        ]),
                    Step::make(__('Dates'))
                        ->schema([
                            Grid::make(2)->schema([
                                DateTimePicker::make('pickup_at')
                                    ->required()
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                DateTimePicker::make('expected_return_at')
                                    ->required()
                                    // Not frozen, but not the way to extend a rental
                                    // either: moving the date without repricing gives
                                    // away the extra days. See the `extend` action.
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false)
                                    ->helperText(fn (?Booking $record): ?string => ($record?->hasStarted() ?? false)
                                        ? __('Use the Extend action — it reprices the rental and posts the difference.')
                                        : null),
                                Select::make('pickup_branch_id')
                                    ->relationship('pickupBranch', 'name')
                                    ->nullable(),
                                Select::make('return_branch_id')
                                    ->relationship('returnBranch', 'name')
                                    ->nullable(),
                            ]),
                        ]),
                    Step::make(__('Pricing'))
                        // Once the rental is invoiced these figures are what the ledger
                        // says. Editing them here would move the booking and leave the
                        // append-only revenue rows behind.
                        ->description(fn (?Booking $record): ?string => ($record?->hasStarted() ?? false)
                            ? __('Locked — revenue for this rental is already posted to the ledger.')
                            : null)
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('daily_rate')->numeric()->required()->prefix('DZD')
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                TextInput::make('days_count')->numeric()->required()
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                TextInput::make('subtotal')->numeric()->required()->prefix('DZD')
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                TextInput::make('extras_total')->numeric()->default(0)->prefix('DZD')
                                    // Never typed by hand: extras exist only as lines on
                                    // the view page, and the lines recompute this column
                                    // (BookingService::syncExtrasTotals). Kept disabled so
                                    // the wizard cannot write a figure the lines disagree with.
                                    ->disabled()
                                    ->helperText(__('bookings.fields.extras_total_helper')),
                                TextInput::make('discount_amount')->numeric()->default(0)->prefix('DZD')
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                TextInput::make('total_amount')->numeric()->required()->prefix('DZD')
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                                TextInput::make('security_deposit_amount')->numeric()->default(0)->prefix('DZD')
                                    ->disabled(fn (?Booking $record): bool => $record?->hasStarted() ?? false),
                            ]),
                        ]),
                    Step::make(__('Options'))
                        ->schema([
                            Grid::make(2)->schema([
                                Toggle::make('with_driver'),
                                Select::make('driver_employee_id')
                                    ->relationship('driverEmployee', 'name')
                                    ->nullable(),
                                Select::make('sales_agent_id')
                                    ->relationship('salesAgent', 'name')
                                    ->nullable(),
                            ]),
                            Section::make('Additional Drivers')
                                ->schema([
                                    Repeater::make('additionalDrivers')
                                        ->relationship()
                                        ->schema([
                                            TextInput::make('full_name')->required(),
                                            TextInput::make('national_id'),
                                            TextInput::make('driving_license_number'),
                                            TextInput::make('phone'),
                                        ])
                                        ->defaultItems(0),
                                ]),
                        ]),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('car.registration_number')
                    ->label(__('Car Plate'))
                    ->searchable(),
                TextColumn::make('customer_id')
                    ->label(__('Customer'))
                    // The full name, not `first_name` — scanning or searching a list of
                    // first names for "Benali" finds nothing.
                    ->formatStateUsing(fn (Booking $record): string => $record->customer?->displayName() ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'customer',
                        fn (Builder $q): Builder => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('company_name', 'ilike', "%{$search}%"),
                    )),
                TextColumn::make('pickup_at')->dateTime()->sortable(),
                TextColumn::make('expected_return_at')
                    ->dateTime()
                    ->sortable()
                    // An overdue row is otherwise indistinguishable from an active one,
                    // and chasing late returns is the whole point of the screen.
                    ->color(fn (Booking $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Booking $record): ?string => $record->isOverdue() ? 'bold' : null)
                    ->icon(fn (Booking $record): ?string => $record->isOverdue() ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (Booking $record): ?string => $record->isOverdue() ? __('Overdue') : null),
                TextColumn::make('status')
                    ->badge(),
                // Money, but deliberately ungated: a receptionist must see the price to
                // take payment. Derived figures (paid, outstanding) are the ones behind
                // reports.view_financials, on the view page.
                TextColumn::make('total_amount')->money('DZD')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(BookingStatus::options()),
                SelectFilter::make('car_id')
                    ->label(__('Car'))
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->preload(),
                // Cross-branch visibility is a permission, not a filter. Without
                // `branches.view_all` the control is not offered at all, and the pinning
                // in getEloquentQuery() holds regardless of what is submitted.
                //
                // Filters `branch_id` — the same column the pin enforces. Offering
                // `pickup_branch_id` here meant the control narrowed by one notion of
                // branch while the scope enforced another, so a manager could filter to
                // a branch and still be looking at a row belonging to a different one.
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
                Filter::make('returns_due_today')
                    ->label(__('Returns due today'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [BookingStatus::Active, BookingStatus::Overdue])
                        ->whereBetween('expected_return_at', [
                            CarbonImmutable::now()->startOfDay(),
                            CarbonImmutable::now()->endOfDay(),
                        ])),
                Filter::make('pickups_today')
                    ->label(__('Pickups today'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                        ->whereBetween('pickup_at', [
                            CarbonImmutable::now()->startOfDay(),
                            CarbonImmutable::now()->endOfDay(),
                        ])),
                Filter::make('overdue')
                    ->label(__('Overdue'))
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Booking> $query */
                        return $query->overdue();
                    }),
                Filter::make('pickup_range')
                    ->schema([
                        DatePicker::make('pickup_from')->label(__('Pickup from')),
                        DatePicker::make('pickup_until')->label(__('Pickup until')),
                    ])
                    // Half-open bounds in the app timezone (Africa/Algiers), like the
                    // "today" filters: the Postgres session runs in UTC, so `whereDate`
                    // would judge a 00:30 pickup as belonging to the previous day.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['pickup_from'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->where('pickup_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['pickup_until'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->where('pickup_at', '<', CarbonImmutable::parse($date)->addDay()->startOfDay()))),
            ])
            // Every lifecycle step goes through BookingService. These used to be
            // bare status updates, which meant a car could be handed over without
            // the rental ever reaching the ledger — no revenue, no receivable, and
            // a dashboard that under-reported the day's takings.
            ->recordActions([
                Action::make('confirm')
                    ->label(__('bookings.actions.confirm'))
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Booking $record, BookingService $bookings): void {
                        $bookings->confirm($record, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.confirmed'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate() && $record->status->is(
                        BookingStatus::Draft,
                        BookingStatus::Pending,
                    )),

                Action::make('checkout')
                    ->label(__('bookings.actions.checkout'))
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->modalHeading(__('bookings.actions.checkout_heading'))
                    ->modalDescription(__('bookings.actions.checkout_description'))
                    ->form([
                        DateTimePicker::make('actual_pickup_at')
                            ->label(__('bookings.fields.actual_pickup_at'))
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_out')
                            ->label(__('bookings.fields.odometer_out'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('fuel_level_out')
                            ->label(__('bookings.fields.fuel_level_out'))
                            ->options(FuelLevel::options())
                            ->required(),
                    ])
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->checkOut($record, $data, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.checked_out'))
                            ->body(__('bookings.notifications.revenue_posted'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate()
                        && $record->status === BookingStatus::Confirmed),

                Action::make('checkin')
                    ->label(__('bookings.actions.checkin'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading(__('bookings.actions.checkin_heading'))
                    ->modalDescription(__('bookings.actions.checkin_description'))
                    ->form([
                        DateTimePicker::make('actual_return_at')
                            ->label(__('bookings.fields.actual_return_at'))
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_in')
                            ->label(__('bookings.fields.odometer_in'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('fuel_level_in')
                            ->label(__('bookings.fields.fuel_level_in'))
                            ->options(FuelLevel::options())
                            ->required(),
                    ])
                    // checkInWithCharges also posts the closeout extras (late hours,
                    // excess km, fuel shortfall) when a check-in condition report
                    // exists; without one it closes the rental with no extra charges.
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->checkInWithCharges($record, $data, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.checked_in'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate() && $record->status->is(
                        BookingStatus::Active,
                        BookingStatus::Overdue,
                    )),

                Action::make('cancel')
                    ->label(__('bookings.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('reason')
                            ->label(__('bookings.fields.cancellation_reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->cancel($record, $data['reason'], Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.cancelled'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate()
                        && ! $record->status->isTerminal()),

                Action::make('record_payment')
                    ->label(__('Record Payment'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->prefix('DZD')
                            ->minValue(0.01)
                            ->required(),
                        Select::make('method')
                            ->label(__('Method'))
                            ->options(PaymentMethod::options())
                            ->required(),
                        // `->relationship()` resolves against the action's record, and
                        // a Booking has no `financialAccount` — it threw a LogicException
                        // the moment the modal opened. Options come from the accounts the
                        // user can actually reach.
                        Select::make('financial_account_id')
                            ->label(__('Financial account'))
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('is_active', true)
                                ->when(
                                    ! (Auth::user()?->can('branches.view_all') ?? false),
                                    fn (Builder $q): Builder => $q->where('branch_id', Auth::user()?->branch_id),
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable(),
                        Textarea::make('notes')->label(__('Notes')),
                    ])
                    // Construction lives in PaymentService: the shape of a customer
                    // payment is domain knowledge, and assembling it here let the UI
                    // define it for one caller only.
                    ->action(function (Booking $record, array $data, PaymentService $payments): void {
                        $payments->recordBookingPayment($record, $data, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('Payment recorded successfully'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate()
                        && ! $record->status->is(BookingStatus::Cancelled, BookingStatus::Draft)),

                Action::make('extend')
                    ->label(__('Extend'))
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->modalHeading(__('Extend rental'))
                    ->modalDescription(__('Reprices the rental for the new return date and posts the difference to the ledger.'))
                    ->form([
                        DateTimePicker::make('expected_return_at')
                            ->label(__('New expected return'))
                            ->required()
                            ->after(fn (Booking $record) => $record->expected_return_at)
                            ->default(fn (Booking $record) => $record->expected_return_at?->addDay()),
                        Textarea::make('reason')->label(__('Reason'))->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->extend(
                            $record,
                            CarbonImmutable::parse($data['expected_return_at']),
                            Auth::user(),
                            $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title(__('Rental extended'))
                            ->body(__('The additional days were priced and posted.'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => static::canOperate() && $record->status->is(
                        BookingStatus::Active,
                        BookingStatus::Overdue,
                    )),

                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (): bool => static::canOperate()),
                // Filament's DeleteAction does not consult canDelete() — that guards the
                // page, and a table action runs in place without visiting one.
                DeleteAction::make()
                    ->visible(fn (Booking $record): bool => static::canDelete($record)),
                // The change trail for this record, pre-filtered to it (ADV-03).
                Action::make('view_history')
                    ->label(__('activity_log.resource.actions.view_history'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(fn (Booking $record): string => ActivityLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => Booking::class],
                            'subject_id' => ['subject_id' => $record->getKey()],
                        ],
                    ], panel: 'admin'))
                    ->visible(fn (): bool => (bool) (Auth::user()?->can('audit.view') ?? false)),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Eager-loads the two relations every row renders — without them the busiest
     * screen in the app fires two extra queries per row, 50 on a page of 25.
     *
     * A user without `branches.view_all` is pinned to their own branch server-side,
     * regardless of any filter they submit.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['car', 'customer']);

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
            RelationManagers\ContractRelationManager::class,
            RelationManagers\ExtrasRelationManager::class,
            RelationManagers\AdditionalDriversRelationManager::class,
            RelationManagers\ConditionReportsRelationManager::class,
            RelationManagers\DepositsRelationManager::class,
            RelationManagers\FinesRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view' => Pages\ViewBooking::route('/{record}'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
