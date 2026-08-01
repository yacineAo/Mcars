<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\InstallmentStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\OwnerInstallment;
use App\Services\Payment\PaymentService;
use App\Services\ReportService;
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

class OwnerInstallmentResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = OwnerInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof OwnerInstallment && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof OwnerInstallment && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // An instalment with no ledger rows is a draft/correction and can be
        // removed. Once E32 has credited 2200 the row is what the posting points
        // at, and the ledger is append-only (ADR-003): deleting it would leave
        // the owner payable standing with nothing behind it.
        return $record instanceof OwnerInstallment
            && ! $record->isPostedToLedger()
            && self::userCanReachBranch($record->branch_id);
    }

    public static function form(Schema $schema): Schema
    {
        // Once the accrual posting is in `transactions` the row is frozen:
        // E32 has credited 2200 with exactly `amount_due`, so editing the
        // figure, the period or the owner afterwards makes the payable disagree
        // with the ledger and no trace exists (ADR-003). Notes and the waived
        // reason stay editable for the row's own paperwork.
        $frozenOnceAccrued = fn (?OwnerInstallment $record): bool => $record !== null && $record->isPostedToLedger();

        return $schema
            ->schema([
                Select::make('car_ownership_agreement_id')
                    ->label(__('owner_installments.fields.agreement'))
                    ->options(fn (): array => self::pinToAccessibleBranches(CarOwnershipAgreement::query())
                        ->with(['car', 'carOwner'])
                        ->orderBy('start_date', 'desc')
                        ->get()
                        ->mapWithKeys(fn (CarOwnershipAgreement $agreement): array => [
                            $agreement->id => self::agreementLabel($agreement),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->disabled($frozenOnceAccrued),
                Select::make('car_owner_id')
                    ->label(__('owner_installments.fields.owner'))
                    ->relationship('carOwner', 'first_name')
                    ->searchable()
                    ->required()
                    ->disabled($frozenOnceAccrued),
                Select::make('car_id')
                    ->label(__('owner_installments.fields.car'))
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->required()
                    ->disabled($frozenOnceAccrued),
                DatePicker::make('period_month')
                    ->label(__('owner_installments.fields.period_month'))
                    ->required()
                    ->disabled($frozenOnceAccrued),
                DatePicker::make('due_date')
                    ->label(__('owner_installments.fields.due_date'))
                    ->required()
                    ->disabled($frozenOnceAccrued),
                TextInput::make('amount_due')
                    ->label(__('owner_installments.fields.amount_due'))
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->disabled($frozenOnceAccrued),
                // Status is set by the generator and by the office marking the
                // row paid or waived; it freezes with the accrual because a flip
                // after E32 would assert a state the ledger never recorded.
                Select::make('status')
                    ->label(__('owner_installments.fields.status'))
                    ->options(InstallmentStatus::options())
                    ->required()
                    ->disabled($frozenOnceAccrued),
                Textarea::make('waived_reason')
                    ->label(__('owner_installments.fields.waived_reason'))
                    ->maxLength(500)
                    ->nullable()
                    ->required(fn (callable $get): bool => $get('status') === InstallmentStatus::Waived->value),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carOwner.first_name')
                    ->label(__('owner_installments.fields.owner'))
                    ->searchable(),
                TextColumn::make('car.registration_number')
                    ->label(__('owner_installments.fields.car'))
                    ->searchable(),
                TextColumn::make('period_month')
                    ->label(__('owner_installments.fields.period_month'))
                    ->date('Y-m')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('owner_installments.fields.due_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('amount_due')
                    ->label(__('owner_installments.fields.amount_due'))
                    ->money('DZD')
                    ->sortable(),
                // The accountant's question, derived from the ledger — there is
                // no stored paid column (ADR-003). Resolved once per request
                // through ReportService's grouped batch, never per row, so the
                // aggregation has exactly one home (see getEloquentQuery()).
                TextColumn::make('paid')
                    ->label(__('owner_installments.fields.paid'))
                    ->money('DZD')
                    ->state(fn (OwnerInstallment $record): string => self::paidAmountFor((int) $record->id))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                // Whether E32 has posted is the row's reason to exist on this
                // screen — the unaccrued queue is what `accrue` clears — so the
                // indicator is plain to see rather than inferred from the action
                // still being offered.
                TextColumn::make('accrual_transaction_id')
                    ->label(__('owner_installments.fields.accrued'))
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state !== null
                        ? __('owner_installments.fields.accrued')
                        : __('owner_installments.fields.not_accrued'))
                    ->color(fn (?int $state): string => $state !== null ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label(__('owner_installments.fields.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                // The accountant's month-end queue: accrued instalments are
                // history, the rest still needs E32.
                Filter::make('unaccrued')
                    ->label(__('owner_installments.filters.unaccrued'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('accrual_transaction_id'))
                    ->toggle(),
                Filter::make('overdue')
                    ->label(__('owner_installments.filters.overdue'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('due_date', '<', today())
                        ->whereNotIn('status', [InstallmentStatus::Paid->value, InstallmentStatus::Waived->value]))
                    ->toggle(),
                SelectFilter::make('period_month')
                    ->label(__('owner_installments.filters.period_month'))
                    ->options(fn (): array => self::pinToAccessibleBranches(OwnerInstallment::query())
                        ->distinct()
                        ->orderByDesc('period_month')
                        ->pluck('period_month', 'period_month')
                        ->map(fn (string $month): string => Carbon::parse($month)->translatedFormat('Y-m'))
                        ->all()),
                SelectFilter::make('car_owner_id')
                    ->label(__('owner_installments.filters.owner'))
                    ->options(fn (): array => self::pinToAccessibleBranches(CarOwner::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (CarOwner $owner): array => [
                            $owner->id => trim($owner->first_name.' '.$owner->last_name),
                        ])
                        ->all())
                    ->searchable(),
                SelectFilter::make('car_id')
                    ->label(__('owner_installments.filters.car'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Car::query())
                        ->orderBy('registration_number')
                        ->pluck('registration_number', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label(__('owner_installments.filters.status'))
                    ->options(InstallmentStatus::options()),
            ])
            ->recordActions([
                // Accrual is what makes a third-party car's P&L honest: the rent is
                // stamped with car_id, so the car reads revenue minus owner rent
                // minus running costs. Without it the car looks pure profit.
                Action::make('accrue')
                    ->label(__('owner_installments.actions.accrue'))
                    ->icon('heroicon-o-book-open')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(__('owner_installments.actions.accrue_description'))
                    ->action(function (OwnerInstallment $record, PaymentService $payments): void {
                        $payments->accrueOwnerInstallment($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('owner_installments.notifications.accrued'))
                            ->send();
                    })
                    ->visible(fn (OwnerInstallment $record): bool => ! $record->isPostedToLedger())
                    // `visible()` is UI-only; a crafted request could still mount
                    // the action with a foreign record, so the same rule is
                    // enforced at execution time — Filament refuses disabled
                    // actions on mount (see canDelete).
                    ->disabled(fn (OwnerInstallment $record): bool => ! self::userCanReachBranch($record->branch_id)),

                ViewAction::make(),
                EditAction::make(),
                // Single delete, only before accrual: an unposted row is a
                // correction, an accrued one is ledger evidence (see canDelete).
                // Visibility is explicit here because the default authorization
                // consults the model policy, which this codebase does not use.
                // `disabled()` re-enforces canDelete on the execution path —
                // `visible()` alone is UI-only.
                DeleteAction::make()
                    ->visible(fn (OwnerInstallment $record): bool => ! $record->isPostedToLedger())
                    ->disabled(fn (OwnerInstallment $record): bool => ! static::canDelete($record)),
            ])
            ->bulkActions([])
            ->defaultSort('due_date', 'asc');
    }

    /**
     * A user without branches.view_all is pinned to the branches they may reach,
     * server-side — the list query and every record-gated page go through
     * ChecksBranchAccess, which fails closed when that set is empty. An
     * instalment carries an owner's rent and what the business owes them; it is
     * no more shareable across branches than the payment that funded it.
     */
    public static function getEloquentQuery(): Builder
    {
        // No ledger aggregation happens here — the paid figure is
        // ReportService::installmentsPaidAmounts(), resolved once per request
        // in paidAmountFor() below. The query only pins the branch scope.
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    /** @var array<int, string>|null */
    private static ?array $paidAmounts = null;

    private static ?string $paidAmountsKey = null;

    /**
     * The paid figure for one instalment: ledger rows that debit 2200
     * (E34/E35). Resolved from ReportService's grouped batch, one query per
     * distinct id set — the aggregation lives there, not in a widget query.
     * Memoised by id-set so a request runs a single query without keeping a
     * stale map across id sets (as a shared test process would).
     */
    private static function paidAmountFor(int $installmentId): string
    {
        $ids = self::pinToAccessibleBranches(OwnerInstallment::query())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $key = implode(',', $ids);

        if (self::$paidAmountsKey !== $key) {
            self::$paidAmounts = app(ReportService::class)->installmentsPaidAmounts($ids);
            self::$paidAmountsKey = $key;
        }

        return (string) (self::$paidAmounts[$installmentId] ?? '0');
    }

    private static function agreementLabel(CarOwnershipAgreement $agreement): string
    {
        $owner = $agreement->carOwner !== null
            ? trim($agreement->carOwner->first_name.' '.$agreement->carOwner->last_name)
            : __('owner_installments.fields.owner');

        return $agreement->car->registration_number.' — '.$owner;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwnerInstallments::route('/'),
            'create' => Pages\CreateOwnerInstallment::route('/create'),
            'view' => Pages\ViewOwnerInstallment::route('/{record}'),
            'edit' => Pages\EditOwnerInstallment::route('/{record}/edit'),
        ];
    }
}
