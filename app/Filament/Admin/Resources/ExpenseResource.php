<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Services\ExpenseService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use RuntimeException;
use UnitEnum;

class ExpenseResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    /**
     * Three separate permissions, per docs/resource/15-expense.md: recording is
     * broad, approving is a manager's act, paying is an accounting act. Anyone
     * holding one of them may read expenses; a supervisor holds none.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('expenses.record')
            || $user->can('expenses.approve')
            || $user->can('expenses.pay')
        );
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('expenses.record') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Expense && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('expenses.record') ?? false)
            && $record instanceof Expense
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // A posted expense is ledger history: the correct correction is a
        // reversal, not a delete. Unposted drafts may be removed freely.
        if (! ($record instanceof Expense)) {
            return false;
        }

        return $record->transaction_id === null
            && (auth()->user()?->can('expenses.record') ?? false)
            && self::userCanReachBranch($record->branch_id);
    }

    /**
     * A user without branches.view_all is pinned to their own branch, server-side
     * — the list query and every record-gated page go through this.
     */
    public static function form(Schema $schema): Schema
    {
        $frozenOncePaid = fn (?Expense $record): bool => $record !== null && $record->status === ExpenseStatus::Paid;

        return $schema
            ->schema([
                Select::make('expense_category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->required()
                    ->reactive()
                    ->disabled($frozenOncePaid),
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->nullable()
                    ->visible(fn (callable $get): bool => self::categoryIsCarRelated($get('expense_category_id')))
                    // An expense that should attribute to a car but has no car_id
                    // silently drops out of per-car profitability — require it here.
                    ->required(fn (callable $get): bool => self::categoryIsCarRelated($get('expense_category_id')))
                    ->disabled($frozenOncePaid),
                Select::make('vendor_id')
                    ->relationship('vendor', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->searchable()
                    ->nullable()
                    ->disabled($frozenOncePaid),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->reactive()
                    // No tax is charged, so the total simply mirrors the amount.
                    // It stays a stored column because an expense may later be
                    // split or adjusted independently of the entered amount.
                    ->afterStateUpdated(function (callable $set, $state): void {
                        $set('total_amount', Money::of((string) ($state ?? 0))->toDecimal());
                    })
                    ->disabled($frozenOncePaid),
                TextInput::make('total_amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->disabled($frozenOncePaid),
                DatePicker::make('incurred_on')
                    ->required()
                    ->disabled($frozenOncePaid),
                TextInput::make('invoice_number')
                    ->maxLength(100)
                    ->disabled($frozenOncePaid),
                Textarea::make('description')
                    ->maxLength(65535)
                    ->disabled($frozenOncePaid),
                // Payment fields (shown when status = paid)
                Select::make('payment_method')
                    ->options(PaymentMethod::options())
                    ->nullable()
                    ->disabled($frozenOncePaid),
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name', function (Builder $query, ?Expense $record): Builder {
                        // The paying account must belong to the expense's branch
                        // (ExpenseService::pay refuses otherwise); never offer a
                        // foreign-branch account here.
                        if ($record !== null) {
                            $query->where('branch_id', $record->branch_id);
                        }

                        return $query;
                    })
                    ->searchable()
                    ->nullable()
                    ->disabled($frozenOncePaid),
                Toggle::make('is_recurring')
                    ->disabled($frozenOncePaid),
                Textarea::make('recurrence_rule')
                    ->maxLength(65535)
                    ->disabled($frozenOncePaid),
                SpatieMediaLibraryFileUpload::make('receipts')
                    ->label('Receipts')
                    ->disk('private')
                    ->collection('receipts')
                    ->multiple()
                    ->visibleOn('edit'),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    private static function categoryIsCarRelated(mixed $categoryId): bool
    {
        if ($categoryId === null) {
            return false;
        }

        return ExpenseCategory::whereKey($categoryId)->value('is_car_related') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('incurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->label('Vendor'),
                TextColumn::make('car.registration_number')
                    ->label('Car'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->translateLabel()
                    ->options(ExpenseStatus::options())
                    // The pending queue is what a manager works when they open
                    // the list; recorded drafts are one click away.
                    ->default(ExpenseStatus::PendingApproval->value),
                Filter::make('incurred_on')
                    ->translateLabel()
                    ->form([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('to')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q): Builder => $q->whereDate('incurred_on', '>=', (string) $data['from']))
                            ->when($data['to'] ?? null, fn (Builder $q): Builder => $q->whereDate('incurred_on', '<=', (string) $data['to']));
                    }),
                SelectFilter::make('expense_category_id')
                    ->label('Category')
                    ->translateLabel()
                    ->relationship('category', 'name')
                    ->searchable(),
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->translateLabel()
                    ->relationship('vendor', 'name')
                    ->searchable(),
                SelectFilter::make('car_id')
                    ->label('Car')
                    ->translateLabel()
                    ->relationship('car', 'registration_number')
                    ->searchable(),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->translateLabel()
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with(['category', 'vendor', 'car', 'financialAccount']);

                $user = auth()->user();
                if ($user !== null && ! $user->can('branches.view_all')) {
                    $query->where('expenses.branch_id', $user->branch_id);
                }

                return $query;
            })
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Expense $record, ExpenseService $service): void {
                        try {
                            $service->approve($record, auth()->user());
                        } catch (RuntimeException $e) {
                            // A stale row (already approved, already paid) must
                            // surface as a refusal, not a 500: the service guard
                            // is the invariant, this notification is the UX.
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Expense approved'))
                            ->send();
                    })
                    ->visible(fn (?Expense $record): bool => $record !== null && in_array($record->status, [ExpenseStatus::Draft, ExpenseStatus::PendingApproval], true) && (auth()->user()?->can('expenses.approve') ?? false)),

                Action::make('pay')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->form([
                        Select::make('payment_method')
                            ->options(PaymentMethod::options())
                            ->required(),
                        Select::make('financial_account_id')
                            ->label('Pay from')
                            ->options(fn (Expense $record): array => FinancialAccount::query()
                                ->where('branch_id', $record->branch_id)
                                ->where('is_active', true)
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Expense $record, array $data, ExpenseService $service): void {
                        $account = FinancialAccount::findOrFail($data['financial_account_id']);

                        try {
                            $service->pay($record, PaymentMethod::from($data['payment_method']), $account, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Expense payment recorded'))
                            ->send();
                    })
                    ->visible(fn (?Expense $record): bool => $record !== null && $record->status === ExpenseStatus::Approved && (auth()->user()?->can('expenses.pay') ?? false)),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
