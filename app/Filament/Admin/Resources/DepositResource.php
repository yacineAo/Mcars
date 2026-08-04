<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DeductionReason;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\DepositResource\Pages;
use App\Models\Deposit;
use App\Services\Payment\DepositService;
use BackedEnum;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DepositResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Deposit && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Deposit && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // A deposit is a liability that has been posted to 2100. Deleting the row
        // would orphan the ledger entries that record it, and those are
        // append-only (ADR-003) — a deposit is settled by refund or forfeit.
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        // Once the liability posting is in `transactions` the row is frozen:
        // the ledger is append-only, so raising the amount afterwards would
        // understate what the business owes with no trace (ADR-003). Notes stay
        // editable for the row's own paperwork.
        $frozenOncePosted = fn (?Deposit $record): bool => $record !== null && $record->isPostedToLedger();

        return $schema
            ->schema([
                Select::make('booking_id')
                    ->relationship('booking', 'reference')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled($frozenOncePosted),
                Select::make('customer_id')
                    ->relationship('customer', 'first_name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled($frozenOncePosted),
                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->disabled($frozenOncePosted),
                // Every method the ledger can post, from the enum — the previous
                // hardcoded four silently omitted CCP and cheque.
                Select::make('method')
                    ->options(PaymentMethod::options())
                    ->required()
                    ->disabled($frozenOncePosted),
                // Cash held in the till is different from a card pre-authorisation.
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozenOncePosted),
                // Status is owned by DepositService: Hold, Deduct and Refund move
                // it. Editing it by hand would desynchronise it from the ledger,
                // which is what actually determines the deposit's real state.
                Select::make('status')
                    ->options(DepositStatus::options())
                    ->default(DepositStatus::Held->value)
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('deposits.fields.status_help')),
                DateTimePicker::make('held_at')
                    ->required()
                    ->disabled($frozenOncePosted),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Resolved once per request, not once per row. The balance is money
        // arithmetic, which belongs to the service, not to a table column.
        $deposits = app(DepositService::class);

        return $table
            ->columns([
                TextColumn::make('booking.reference'),
                TextColumn::make('customer.first_name')->label('Customer'),
                // Labelled as held, never bare "Amount": a deposit is money owed
                // back to the customer, and beside revenue figures a bare amount
                // invites the misreading the accounting model exists to prevent.
                TextColumn::make('amount')
                    ->label(__('deposits.fields.amount_held'))
                    ->tooltip(__('deposits.fields.amount_held_help'))
                    ->money('DZD')
                    ->sortable(),
                // What the screen is about: amount less deductions, derived —
                // there is no stored balance column (ADR-003). The sum comes from
                // the withSum eager load below, so this costs no extra query.
                TextColumn::make('remaining_balance')
                    ->label(__('deposits.fields.remaining_balance'))
                    ->state(fn (Deposit $record): string => $deposits
                        ->remainingBalanceFrom(
                            $record,
                            (string) ($record->deductions_sum_amount ?? '0'),
                            (string) ($record->refunds_sum_amount ?? '0'),
                        )
                        ->toDecimal())
                    ->money('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                TextColumn::make('method'),
                TextColumn::make('status')->badge(),
                TextColumn::make('held_at')->dateTime()->sortable(),
            ])
            ->filters([
                // Outstanding deposits are the liability the business needs to
                // see; settled ones are history. Held and PartiallyRefunded are
                // both outstanding, so the default view shows exactly those.
                SelectFilter::make('status')
                    ->options(DepositStatus::options())
                    ->multiple()
                    ->default([
                        DepositStatus::Held->value,
                        DepositStatus::PartiallyRefunded->value,
                    ]),
                Filter::make('held_at')
                    ->label(__('deposits.filters.held_at'))
                    ->form([
                        DatePicker::make('from')->label(__('deposits.filters.from')),
                        DatePicker::make('to')->label(__('deposits.filters.to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q): Builder => $q->whereDate('held_at', '>=', (string) $data['from']))
                            ->when($data['to'] ?? null, fn (Builder $q): Builder => $q->whereDate('held_at', '<=', (string) $data['to']));
                    }),
            ])
            // A deposit is a liability, never revenue. Each of these posts the
            // matching entry: holding credits Security Deposits Held, a deduction
            // converts part of it to revenue, a refund clears the liability.
            ->recordActions([
                Action::make('hold')
                    ->label(__('deposits.actions.hold'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Deposit $record, DepositService $deposits): void {
                        $deposits->hold($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.held'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => ! $record->isPostedToLedger()),

                Action::make('deduct')
                    ->label(__('deposits.actions.deduct'))
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->modalDescription(__('deposits.actions.deduct_description'))
                    ->form([
                        Select::make('reason')
                            ->label(__('deposits.fields.reason'))
                            ->options(DeductionReason::options())
                            ->required(),
                        TextInput::make('amount')
                            ->label(__('deposits.fields.amount'))
                            ->numeric()
                            ->prefix('DZD')
                            ->minValue(0.01)
                            ->required(),
                        Textarea::make('description')
                            ->label(__('deposits.fields.description'))
                            ->maxLength(500),
                    ])
                    ->action(function (Deposit $record, array $data, DepositService $deposits): void {
                        // The service owns the deduction row's shape — including
                        // created_by_id — and refuses a deduction that would
                        // exceed the deposit, so the operator sees why rather
                        // than creating a negative balance.
                        $deposits->deductFromData($record, $data, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.deducted'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => $record->isPostedToLedger()
                        && $record->status->is(DepositStatus::Held, DepositStatus::PartiallyRefunded)),

                Action::make('refund')
                    ->label(__('deposits.actions.refund'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->modalDescription(__('deposits.actions.refund_description'))
                    ->form([
                        TextInput::make('amount')
                            ->label(__('deposits.fields.refund_amount'))
                            ->numeric()
                            ->prefix('DZD')
                            ->helperText(__('deposits.fields.refund_help')),
                    ])
                    ->action(function (Deposit $record, array $data, DepositService $deposits): void {
                        $deposits->refund(
                            $record,
                            ($data['amount'] ?? null) !== null ? (string) $data['amount'] : null,
                            (int) Auth::id(),
                        );

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.refunded'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => $record->isPostedToLedger()
                        && $record->status->is(DepositStatus::Held, DepositStatus::PartiallyRefunded)),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('held_at', 'desc');
    }

    /**
     * A user without branches.view_all is pinned to the branches they may reach,
     * server-side — the list query and every record-gated page go through
     * ChecksBranchAccess, which fails closed when that set is empty. A deposit
     * carries a customer's name and what the business owes them; it is no more
     * shareable across branches than the payment that funded it.
     */
    public static function getEloquentQuery(): Builder
    {
        // Both sums the balance needs, eager-loaded, so the column costs no
        // per-row query. Refunds have no deduction row — see
        // DepositService::remainingBalance().
        $query = parent::getEloquentQuery()
            ->withSum('deductions', 'amount')
            ->withSum([
                'ledgerTransactions as refunds_sum_amount' => fn (Builder $q): Builder => $q
                    ->where('type', TransactionType::DepositRefund),
            ], 'amount');

        return self::pinToAccessibleBranches($query);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeposits::route('/'),
            'create' => Pages\CreateDeposit::route('/create'),
            'view' => Pages\ViewDeposit::route('/{record}'),
            'edit' => Pages\EditDeposit::route('/{record}/edit'),
        ];
    }
}
