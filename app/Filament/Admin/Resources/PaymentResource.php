<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\PaymentResource\Pages;
use App\Filament\Admin\Resources\PaymentResource\RelationManagers\TransactionsRelationManager;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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

class PaymentResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    /**
     * The counter clerk records money, the accountant audits it.
     *
     * Deliberately wider than DepositResource's gate on reports.view_financials:
     * the role matrix in docs/02-filament-panels.md gives the receptionist
     * "payments + cash only" on Finance, and a receptionist who cannot open the
     * payments screen cannot retry a payment whose posting failed. The two
     * permissions mirror CashSessionResource: the till operator sees the cash
     * side, the financial reader sees the books.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('cash_sessions.operate')
            || $user->can('reports.view_financials')
        );
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Payment && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Payment
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // A payment row is money-movement evidence. Unposted payments are
        // completed through post_to_ledger, never deleted; posted payments are
        // ledger history and the append-only rule says reverse, don't delete.
        return false;
    }

    /**
     * A user without branches.view_all is pinned to the branches they may reach,
     * server-side — the list query and every record-gated page go through
     * ChecksBranchAccess, which fails closed when that set is empty.
     */
    public static function getEloquentQuery(): Builder
    {
        // withExists keeps the posted indicator off the per-row query path.
        return self::pinToAccessibleBranches(
            parent::getEloquentQuery()->withExists('ledgerTransactions'),
        );
    }

    public static function form(Schema $schema): Schema
    {
        // Once the ledger rows exist they are append-only: editing the payment
        // afterwards makes the two disagree with no trace (ADR-003). Notes and
        // the external reference stay editable for the row's own paperwork.
        $frozenOncePosted = fn (?Payment $record): bool => $record !== null && $record->isPostedToLedger();

        return $schema
            ->schema([
                TextInput::make('reference')
                    ->required()
                    ->maxLength(32)
                    // A document number: generated or typed once at the counter,
                    // never renumbered.
                    ->disabled(fn (?Payment $record): bool => $record !== null),
                Select::make('direction')
                    ->options(PaymentDirection::options())
                    ->required()
                    ->disabled($frozenOncePosted),
                Select::make('method')
                    ->options(PaymentMethod::options())
                    ->required()
                    ->reactive()
                    ->disabled($frozenOncePosted),
                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->disabled($frozenOncePosted),
                DatePicker::make('paid_at')
                    ->required()
                    ->disabled($frozenOncePosted),
                Select::make('status')
                    ->options(PaymentStatus::options())
                    ->required()
                    ->disabled($frozenOncePosted),
                Select::make('customer_id')
                    ->relationship('customer', 'first_name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozenOncePosted),
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozenOncePosted),
                // Payment methods are Algerian — cash, CCP, BaridiMob, bank
                // transfer, card, cheque — and each carries its own identifying
                // field. `external_reference` holds it; only the relevant field
                // is offered, never all six at once.
                TextInput::make('external_reference')
                    ->maxLength(128)
                    ->nullable()
                    ->disabled($frozenOncePosted)
                    ->label(fn (callable $get): string => match ($get('method')) {
                        PaymentMethod::BankTransfer->value => __('payments.fields.rib'),
                        PaymentMethod::Ccp->value => __('payments.fields.ccp_account'),
                        PaymentMethod::BaridiMob->value => __('payments.fields.baridimob_number'),
                        PaymentMethod::Cheque->value => __('payments.fields.cheque_number'),
                        PaymentMethod::Card->value => __('payments.fields.card_reference'),
                        default => __('payments.fields.external_reference'),
                    })
                    ->visible(fn (callable $get): bool => in_array($get('method'), [
                        PaymentMethod::BankTransfer->value,
                        PaymentMethod::Ccp->value,
                        PaymentMethod::BaridiMob->value,
                        PaymentMethod::Cheque->value,
                        PaymentMethod::Card->value,
                    ], true)),
                DatePicker::make('cheque_due_date')
                    ->nullable()
                    ->disabled($frozenOncePosted)
                    ->visible(fn (callable $get): bool => $get('method') === PaymentMethod::Cheque->value),
                Textarea::make('notes')
                    ->maxLength(65535)
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('ledger_transactions_exists')
                    ->label(__('payments.fields.posted'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('payments.fields.posted')
                        : __('payments.fields.not_posted'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('direction')->badge(),
                TextColumn::make('method'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('paid_at')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('customer.first_name')->label(__('payments.fields.customer')),
                TextColumn::make('branch.name')->label(__('payments.fields.branch')),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options(PaymentDirection::options()),
                SelectFilter::make('method')
                    ->options(PaymentMethod::options()),
                SelectFilter::make('status')
                    ->options(PaymentStatus::options()),
                // The accountant's queue: the reason post_to_ledger exists. A
                // payment that never reached the ledger is invisible to every
                // balance, so this filter is the reconciliation screen.
                Filter::make('unposted')
                    ->label(__('payments.filters.unposted'))
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('ledgerTransactions'))
                    ->toggle(),
                Filter::make('paid_at')
                    ->label(__('payments.filters.paid_at'))
                    ->form([
                        DatePicker::make('from')->label(__('payments.filters.from')),
                        DatePicker::make('to')->label(__('payments.filters.to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q): Builder => $q->whereDate('paid_at', '>=', (string) $data['from']))
                            ->when($data['to'] ?? null, fn (Builder $q): Builder => $q->whereDate('paid_at', '<=', (string) $data['to']));
                    }),
                SelectFilter::make('branch_id')
                    ->label(__('payments.fields.branch'))
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->recordActions([
                // Payments post automatically on create. This is the retry path for
                // a row whose posting failed, and the reason a payment can never be
                // silently absent from the ledger.
                Action::make('post_to_ledger')
                    ->label(__('payments.actions.post'))
                    ->icon('heroicon-o-book-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Payment $record, PaymentService $payments): void {
                        $payments->recordPayment($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('payments.notifications.posted'))
                            ->send();
                    })
                    ->visible(fn (Payment $record): bool => ! $record->isPostedToLedger()),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('paid_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'view' => Pages\ViewPayment::route('/{record}'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
