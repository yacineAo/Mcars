<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\PaymentScheduleResource\Pages;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\PaymentSchedule;
use App\Services\Payment\PaymentScheduleService;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class PaymentScheduleResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = PaymentSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof PaymentSchedule && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof PaymentSchedule
            && $record->status === InstallmentStatus::Pending
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // Plans are financial commitments. They are settled by marking an
        // instalment paid or waived, never by deleting evidence from the queue.
        return false;
    }

    public static function canCreate(): bool
    {
        // The generator is the only creation path, because a plan is a set of rows
        // that must sum back to the agreed total. One typed row bypasses
        // Money::allocate() and leaves the plan a centime short of itself.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return self::pinToAccessibleBranches(
            parent::getEloquentQuery()
                ->select('payment_schedules.*')
                // How many instalments the plan has, which must not move when the
                // table is filtered. A window function counts only the rows that
                // survived the WHERE, so ticking "Overdue" turned "1 of 3" into
                // "1 of 1"; a correlated subquery answers the question actually
                // being asked, independently of the outer filters.
                ->selectSub(self::planSizeSubquery(), 'plan_installments_count')
                ->with('customer'),
        );
    }

    public static function form(Schema $schema): Schema
    {
        $isPending = fn (?PaymentSchedule $record): bool => $record?->status === InstallmentStatus::Pending;

        return $schema->schema([
            Select::make('customer_id')
                ->label(__('payment_schedules.fields.customer'))
                ->relationship('customer', 'first_name')
                ->searchable()
                ->preload()
                ->required()
                ->disabled(),
            TextInput::make('sequence')->label(__('payment_schedules.fields.sequence'))->numeric()->disabled(),
            DatePicker::make('due_date')
                ->label(__('payment_schedules.fields.due_date'))
                ->required()
                // Never earlier than where it already sits. A plan can legitimately
                // hold a pending line whose date has passed, and rejecting the save
                // of a form nobody edited is worse than allowing it to stay put.
                ->minDate(function (?PaymentSchedule $record): Carbon {
                    $current = $record?->due_date;

                    return $current !== null && $current->isPast() ? $current : Carbon::today();
                })
                ->disabled(fn (?PaymentSchedule $record): bool => ! $isPending($record)),
            TextInput::make('amount')->label(__('payment_schedules.fields.amount'))->numeric()->required()->prefix('DZD')->disabled(),
            Select::make('status')->label(__('payment_schedules.fields.status'))->options(InstallmentStatus::options())->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.first_name')->label(__('payment_schedules.fields.customer'))->searchable(),
                TextColumn::make('sequence')
                    ->label(__('payment_schedules.fields.sequence'))
                    ->formatStateUsing(function (PaymentSchedule $record): string {
                        $total = (int) $record->getAttribute('plan_installments_count');

                        return $total > 0
                            ? __('payment_schedules.fields.sequence_of', ['sequence' => $record->sequence, 'total' => $total])
                            : (string) $record->sequence;
                    })
                    ->sortable(),
                TextColumn::make('due_date')->label(__('payment_schedules.fields.due_date'))->date()->sortable(),
                TextColumn::make('amount')->label(__('payment_schedules.fields.amount'))->money('DZD')->sortable(),
                TextColumn::make('status')->label(__('payment_schedules.fields.status'))->badge(),
                IconColumn::make('reminder_sent_at')
                    ->label(__('payment_schedules.fields.reminder_sent'))
                    ->boolean()
                    ->state(fn (PaymentSchedule $record): bool => $record->reminder_sent_at !== null),
            ])
            ->filters([
                Filter::make('overdue')
                    ->label(__('payment_schedules.filters.overdue'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('due_date', '<', today())
                        ->whereNotIn('status', [InstallmentStatus::Paid->value, InstallmentStatus::Waived->value]))
                    ->toggle(),
                Filter::make('due_this_month')
                    ->label(__('payment_schedules.filters.due_this_month'))
                    ->query(fn (Builder $query): Builder => $query->whereBetween('due_date', [today()->startOfMonth(), today()->endOfMonth()]))
                    ->toggle(),
                SelectFilter::make('status')
                    ->label(__('payment_schedules.filters.status'))
                    ->options(InstallmentStatus::options()),
                // Options are pinned as well as rows: the table already hides other
                // branches' instalments, but a `->relationship()` dropdown would still
                // enumerate every customer in the business by name.
                SelectFilter::make('customer_id')
                    ->label(__('payment_schedules.filters.customer'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Customer::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->displayName()])
                        ->all())
                    ->searchable(),
            ])
            ->groups([
                // A plan is identified by the morph pair, not by the id alone —
                // grouping on `schedulable_id` filed Booking #7 and Contract #7 under
                // one heading. Every hook that touches the key has to agree on that.
                Group::make('schedulable_id')
                    ->label(__('payment_schedules.groups.plan'))
                    ->getKeyFromRecordUsing(fn (PaymentSchedule $record): string => self::planKey($record))
                    ->getTitleFromRecordUsing(fn (PaymentSchedule $record): string => self::planTitle($record))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('schedulable_type', $direction)
                        ->orderBy('schedulable_id', $direction))
                    ->groupQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query
                        ->groupBy('schedulable_type', 'schedulable_id'))
                    ->scopeQueryUsing(fn (Builder $query, PaymentSchedule $record): Builder => $query
                        ->where('schedulable_type', $record->schedulable_type)
                        ->where('schedulable_id', $record->schedulable_id))
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key): Builder {
                        [$type, $id] = array_pad(explode('|', (string) $key, 2), 2, '');

                        return $query->where('schedulable_type', $type)->where('schedulable_id', $id);
                    }),
            ])
            ->defaultGroup('schedulable_id')
            ->recordActions([
                Action::make('mark_paid')
                    ->label(__('payment_schedules.actions.record_payment'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->form([
                        Select::make('method')
                            ->label(__('payment_schedules.fields.method'))
                            ->options(PaymentMethod::options())
                            ->required(),
                        // `->relationship()` resolves against the action's record, and
                        // a PaymentSchedule has no `financialAccount` — it threw a
                        // LogicException the moment the action was submitted, which is
                        // the same trap BookingResource fell into. Options come from
                        // the accounts the user can actually reach.
                        Select::make('financial_account_id')
                            ->label(__('payment_schedules.fields.financial_account'))
                            ->options(fn (): array => self::pinToAccessibleBranches(
                                FinancialAccount::query()->where('is_active', true),
                            )->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])
                    ->action(function (PaymentSchedule $record, array $data, PaymentScheduleService $schedules): void {
                        $schedules->recordPayment($record, $data, (int) Auth::id());
                        Notification::make()->success()->title(__('payment_schedules.notifications.payment_recorded'))->send();
                    })
                    ->visible(fn (PaymentSchedule $record): bool => self::isUnpaid($record)),
                Action::make('reschedule')
                    ->label(__('payment_schedules.actions.reschedule'))
                    ->icon('heroicon-o-calendar-days')
                    ->form([
                        DatePicker::make('due_date')
                            ->label(__('payment_schedules.fields.due_date'))
                            ->required()
                            ->minDate(today()),
                    ])
                    ->action(function (PaymentSchedule $record, array $data, PaymentScheduleService $schedules): void {
                        $schedules->reschedule($record, Carbon::parse($data['due_date']));
                        Notification::make()->success()->title(__('payment_schedules.notifications.rescheduled'))->send();
                    })
                    // Overdue too: an instalment whose date has passed is precisely the
                    // one that needs moving, and the service permits it.
                    ->visible(fn (PaymentSchedule $record): bool => self::isUnpaid($record)),
                Action::make('waive')
                    ->label(__('payment_schedules.actions.waive'))
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('payment_schedules.actions.waive_confirm'))
                    ->form([
                        Textarea::make('reason')
                            ->label(__('payment_schedules.fields.waived_reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (PaymentSchedule $record, array $data, PaymentScheduleService $schedules): void {
                        $schedules->waive($record, $data['reason'], (int) Auth::id());
                        Notification::make()->success()->title(__('payment_schedules.notifications.waived'))->send();
                    })
                    // Waiving is the last resort for an unpaid line; a paid one
                    // stays on the ledger and a waived one cannot change again.
                    ->visible(fn (PaymentSchedule $record): bool => self::isUnpaid($record)),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('sequence');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentSchedules::route('/'),
            'view' => Pages\ViewPaymentSchedule::route('/{record}'),
            'edit' => Pages\EditPaymentSchedule::route('/{record}/edit'),
        ];
    }

    private static function isUnpaid(PaymentSchedule $record): bool
    {
        return in_array($record->status, [InstallmentStatus::Pending, InstallmentStatus::Overdue], true);
    }

    /** The grouping key: the polymorphic pair, not either half on its own. */
    private static function planKey(PaymentSchedule $record): string
    {
        return ($record->schedulable_type ?? '').'|'.($record->schedulable_id ?? '');
    }

    private static function planTitle(PaymentSchedule $record): string
    {
        $type = match ($record->schedulable_type) {
            Booking::class => __('payment_schedules.fields.booking'),
            Contract::class => __('payment_schedules.fields.contract'),
            default => null,
        };

        if ($type === null || $record->schedulable_id === null) {
            return __('payment_schedules.groups.unassigned');
        }

        return __('payment_schedules.groups.plan_title', [
            'type' => $type,
            'id' => $record->schedulable_id,
        ]);
    }

    private static function planSizeSubquery(): QueryBuilder
    {
        return DB::table('payment_schedules as plan_siblings')
            ->selectRaw('count(*)')
            ->whereColumn('plan_siblings.schedulable_type', 'payment_schedules.schedulable_type')
            ->whereColumn('plan_siblings.schedulable_id', 'payment_schedules.schedulable_id');
    }
}
