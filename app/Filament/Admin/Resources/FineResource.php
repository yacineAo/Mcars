<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\FineResource\Pages;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Fine;
use App\Services\Payment\FineLiabilityService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class FineResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Fine::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    /**
     * Reading the queue is broad — the desk that received the notice must find
     * it later. Deciding who pays is `fines.manage` and posts E49/E50.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('fines.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('fines.manage') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Fine
            && Auth::user()?->can('fines.view')
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Fine
            && Auth::user()?->can('fines.manage')
            && ! $record->isPostedToLedger()
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // An unposted fine is just an entry that was never committed; deleting
        // it is correction. A posted one stays: the receivable or the absorbed
        // expense is on the ledger and the only way back is a reversal.
        return $record instanceof Fine
            && Auth::user()?->can('fines.manage')
            && ! $record->isPostedToLedger()
            && self::userCanReachBranch($record->branch_id);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        $suggestMatch = static function (callable $get, callable $set): void {
            $carId = $get('car_id');
            $violationAt = $get('violation_at');

            if ($carId === null || $violationAt === null) {
                return;
            }

            // Suggestion, not decision: the matched booking's customer and
            // contract pre-fill the form, and the clerk can override them.
            $booking = app(FineLiabilityService::class)
                ->matchActiveBooking((int) $carId, Carbon::parse($violationAt));

            if ($booking === null) {
                $set('booking_id', null);
                $set('customer_id', null);
                $set('contract_id', null);

                return;
            }

            $set('booking_id', $booking->id);
            $set('customer_id', $booking->customer_id);
            $set('contract_id', $booking->contract?->id);
        };

        $frozen = fn (?Fine $record): bool => $record !== null && $record->isPostedToLedger();

        return $schema
            ->schema([
                TextInput::make('reference')->required()->maxLength(32),
                Select::make('car_id')
                    ->label(__('fines.fields.car'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Car::query())
                        ->orderBy('registration_number')
                        ->pluck('registration_number', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated($suggestMatch)
                    ->disabled($frozen),
                Select::make('customer_id')
                    ->label(__('fines.fields.customer'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Customer::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->displayName()])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozen),
                Select::make('booking_id')
                    ->label(__('fines.fields.booking'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Booking::query())
                        ->orderBy('reference')
                        ->pluck('reference', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozen),
                Select::make('contract_id')
                    ->label(__('fines.fields.contract'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Contract::query())
                        ->orderBy('contract_number')
                        ->pluck('contract_number', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozen),
                Select::make('type')->options(FineType::options())->required(),
                TextInput::make('notice_number')->nullable(),
                TextInput::make('authority')->nullable(),
                DateTimePicker::make('violation_at')
                    ->required()
                    ->live()
                    ->afterStateUpdated($suggestMatch)
                    ->disabled($frozen),
                TextInput::make('location')->nullable(),
                DateTimePicker::make('received_at')->required(),
                DatePicker::make('due_date')->nullable(),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->reactive()
                    // The sum is computed here and never typed: `total_amount`
                    // is a stored column, but the only way into it is
                    // amount + late_penalty_amount through Money.
                    ->afterStateUpdated(static function (callable $get, callable $set): void {
                        $set('total_amount', Money::of((string) ($get('amount') ?? 0))
                            ->plus(Money::of((string) ($get('late_penalty_amount') ?? 0)))
                            ->toDecimal());
                    })
                    ->disabled($frozen),
                TextInput::make('late_penalty_amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->default(0)
                    ->reactive()
                    ->afterStateUpdated(static function (callable $get, callable $set): void {
                        $set('total_amount', Money::of((string) ($get('amount') ?? 0))
                            ->plus(Money::of((string) ($get('late_penalty_amount') ?? 0)))
                            ->toDecimal());
                    })
                    ->disabled($frozen),
                TextInput::make('total_amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    // `disabled()` alone would stop the field from dehydrating,
                    // and `total_amount` is a NOT NULL column: the create form
                    // could never save. `dehydrated()` keeps the composed total
                    // (DepositResource's status field is the same shape).
                    ->disabled()
                    ->dehydrated(),
                Select::make('liability')
                    ->options(FineLiability::options())
                    ->default(FineLiability::PendingReview->value)
                    // The decision is the row action's, not the form's: assigning
                    // posts E49/E50, and the row and the ledger must never
                    // disagree. Read-only here; `mutateFormDataBefore*` re-asserts
                    // it against crafted payloads.
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('fines.fields.liability_help')),
                Textarea::make('liability_note')->nullable(),
                Select::make('status')
                    ->options(FineStatus::options())
                    ->default(FineStatus::PendingReview->value)
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('fines.fields.status_help')),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $canManage = fn (): bool => Auth::user()?->can('fines.manage') ?? false;
        $canDecide = fn (Fine $record): bool => $canManage()
            && ! $record->isPostedToLedger()
            && self::userCanReachBranch($record->branch_id);

        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('notice_number')
                    ->label(__('fines.fields.notice_number'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('car.registration_number')
                    ->label(__('fines.fields.car'))
                    ->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('violation_at')
                    ->label(__('fines.fields.violation_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label(__('fines.fields.total_amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('liability')->label(__('fines.fields.liability'))->badge(),
                TextColumn::make('status')->label(__('fines.fields.status'))->badge(),
            ])
            ->filters([
                Filter::make('pending_liability')
                    ->label(__('fines.filters.pending_liability'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('liability', FineLiability::PendingReview->value))
                    // The queue someone works through when the post arrives:
                    // the screen opens on what is still undecided.
                    ->default(true)
                    ->toggle(),
                SelectFilter::make('type')
                    ->label(__('fines.fields.type'))
                    ->options(FineType::options()),
                Filter::make('violation_range')
                    ->label(__('fines.filters.violation_range'))
                    ->schema([
                        DatePicker::make('violated_from')->label(__('fines.filters.violated_from')),
                        DatePicker::make('violated_until')->label(__('fines.filters.violated_until')),
                    ])
                    // Half-open bounds in the app timezone (Africa/Algiers), like
                    // the BookingResource pickup range: the Postgres session runs
                    // in UTC, so whereDate would judge a 00:30 offence as the
                    // previous day.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['violated_from'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->where('violation_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['violated_until'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->where('violation_at', '<', CarbonImmutable::parse($date)->addDay()->startOfDay()))),
                // Options are pinned as well as rows: the table already hides
                // other branches' fines, but a `->relationship()` dropdown would
                // still enumerate every car or customer in the business.
                SelectFilter::make('car_id')
                    ->label(__('fines.fields.car'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Car::query())
                        ->orderBy('registration_number')
                        ->pluck('registration_number', 'id')
                        ->all()),
                SelectFilter::make('customer_id')
                    ->label(__('fines.fields.customer'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Customer::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->displayName()])
                        ->all()),
            ])
            ->recordActions([
                // The service proposes who was driving by matching the offence
                // time against the car's active booking (ADR-011) and persists
                // the suggestion; a human confirms it. Confirming "customer"
                // posts E49, "company" posts E50.
                Action::make('propose_liability')
                    ->label(__('fines.actions.propose'))
                    ->icon('heroicon-o-light-bulb')
                    ->color('gray')
                    ->action(function (Fine $record, FineLiabilityService $fines): void {
                        $fines->proposeLiability($record);

                        Notification::make()
                            ->success()
                            ->title(__('fines.notifications.proposed'))
                            ->send();
                    })
                    // `visible()` is evaluated per row — but also, once, without
                    // a record while the table resolves the action group — so
                    // both closures are null-safe; the `disabled()` twin is what
                    // actually refuses a crafted call at mount time.
                    ->visible(fn (?Fine $record): bool => $record !== null && $canDecide($record))
                    ->disabled(fn (?Fine $record): bool => $record === null || ! $canDecide($record)),

                Action::make('assign_liability')
                    ->label(__('fines.actions.assign'))
                    ->icon('heroicon-o-user-plus')
                    ->color('warning')
                    ->modalDescription(__('fines.actions.assign_description'))
                    ->form([
                        Select::make('liability')
                            ->label(__('fines.fields.liability'))
                            // E49 and E50 are the two postable outcomes. Owner
                            // liability (E56) needs the owner payable flow and
                            // is deliberately not offered here yet.
                            ->options(collect(FineLiability::options())->except(['pending_review', 'owner'])->all())
                            ->required(),
                    ])
                    ->action(function (Fine $record, array $data, FineLiabilityService $fines): void {
                        $fines->confirmLiability($record, $data['liability'], (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('fines.notifications.assigned'))
                            ->send();
                    })
                    ->visible(fn (?Fine $record): bool => $record !== null && $canDecide($record))
                    ->disabled(fn (?Fine $record): bool => $record === null || ! $canDecide($record)),

                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (?Fine $record): bool => $record !== null && static::canDelete($record))
                    ->disabled(fn (?Fine $record): bool => $record === null || ! static::canDelete($record)),
            ])
            ->bulkActions([])
            ->defaultSort('violation_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFines::route('/'),
            'create' => Pages\CreateFine::route('/create'),
            'view' => Pages\ViewFine::route('/{record}'),
            'edit' => Pages\EditFine::route('/{record}/edit'),
        ];
    }
}
