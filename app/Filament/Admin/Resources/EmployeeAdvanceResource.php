<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AdvanceStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Closure;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EmployeeAdvanceResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = EmployeeAdvance::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('hr.view_salary') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('hr.manage') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EmployeeAdvance && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (Auth::user()?->can('hr.manage') ?? false)
            && $record instanceof EmployeeAdvance
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // Money records: the payout either posted to the ledger (append-only,
        // ADR-003) or was refused — neither is deleted. An unwanted request is
        // rejected, an unwanted advance does not happen.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        // Once approved the payout is on the ledger (E61): the amount, the
        // employee and the date are frozen, because changing any of them
        // afterwards would leave the advance and its posting disagreeing. Only
        // the paperwork (reason, notes) stays editable.
        $frozenOncePaid = fn (?EmployeeAdvance $record): bool => $record !== null
            && $record->status !== AdvanceStatus::Requested;

        return $schema
            ->schema([
                Select::make('employee_id')
                    ->label(__('employee_advances.fields.employee'))
                    // Pinned like every other options list: an enumeration here
                    // would list every branch's staff.
                    ->options(fn (): array => self::pinToAccessibleBranches(Employee::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => $employee->first_name.' '.$employee->last_name,
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    // Self-dealing is refused at the form: an advance on your
                    // own employee record is never worth typing.
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (Employee::whereKey($value)->value('user_id') === auth()->id()) {
                                $fail(__('employee_advances.validation.self_granted'));
                            }
                        },
                    ])
                    ->disabled($frozenOncePaid),
                TextInput::make('amount')
                    ->label(__('employee_advances.fields.amount'))
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->disabled($frozenOncePaid),
                DatePicker::make('advanced_on')
                    ->label(__('employee_advances.fields.advanced_on'))
                    ->required()
                    ->disabled($frozenOncePaid),
                Textarea::make('reason')
                    ->label(__('employee_advances.fields.reason'))
                    ->nullable(),
                // Status is owned by PaymentService: approve posts E61 and
                // moves to outstanding, reject moves to rejected, payroll
                // recovery moves to recovered. It is never typed.
                Select::make('status')
                    ->label(__('employee_advances.fields.status'))
                    ->options(AdvanceStatus::options())
                    ->default(AdvanceStatus::Requested->value)
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('employee_advances.fields.status_help')),
                Textarea::make('notes')
                    ->label(__('employee_advances.fields.notes'))
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label(__('employee_advances.columns.employee'))
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('employee_advances.columns.amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('advanced_on')
                    ->label(__('employee_advances.columns.advanced_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('employee_advances.columns.status'))
                    ->badge(),
                // Derived from the recovery stamp: null means still owed. The
                // stamp is written by the payroll run that deducts the advance.
                TextColumn::make('recoveredInPayrollItem.payrollRun.period_month')
                    ->label(__('employee_advances.columns.recovered_in'))
                    ->date('Y-m')
                    ->placeholder('—'),
            ])
            ->filters([
                // The workbench: advances that are still live — a `requested`
                // one waits for approval, an `outstanding` one is owed. Settled
                // history (rejected, recovered, written off) hides unless asked.
                TernaryFilter::make('open')
                    ->label(__('employee_advances.filters.open'))
                    ->default(true)
                    ->trueLabel(__('employee_advances.filters.open'))
                    ->falseLabel(__('employee_advances.filters.settled'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', [
                            AdvanceStatus::Requested->value,
                            AdvanceStatus::Outstanding->value,
                        ]),
                        false: fn (Builder $query): Builder => $query->whereNotIn('status', [
                            AdvanceStatus::Requested->value,
                            AdvanceStatus::Outstanding->value,
                        ]),
                    ),
                SelectFilter::make('status')
                    ->options(AdvanceStatus::options()),
                SelectFilter::make('employee_id')
                    ->label(__('employee_advances.filters.employee'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Employee::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => $employee->first_name.' '.$employee->last_name,
                        ])
                        ->all()),
            ])
            ->recordActions([
                // Approve = authorise AND pay: E61 posts in the same
                // transaction as the status change, so the ledger entry is the
                // recorded authorisation the request lacked.
                Action::make('approve')
                    ->label(__('employee_advances.actions.approve'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('employee_advances.actions.approve_description'))
                    ->action(function (EmployeeAdvance $record, PaymentService $payments): void {
                        try {
                            $payments->approveAdvance($record, (int) Auth::id());
                        } catch (\DomainException $e) {
                            // A stale row (already approved, already rejected)
                            // surfaces as a refusal, not a 500.
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('employee_advances.notifications.approved'))
                            ->send();
                    })
                    ->visible(fn (?EmployeeAdvance $record): bool => $record !== null
                        && $record->status === AdvanceStatus::Requested
                        && (Auth::user()?->can('hr.manage') ?? false))
                    ->disabled(fn (?EmployeeAdvance $record): bool => $record === null
                        || $record->status !== AdvanceStatus::Requested
                        || ! (Auth::user()?->can('hr.manage') ?? false)),

                Action::make('reject')
                    ->label(__('employee_advances.actions.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('employee_advances.actions.reject_description'))
                    ->action(function (EmployeeAdvance $record, PaymentService $payments): void {
                        try {
                            $payments->rejectAdvance($record);
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('employee_advances.notifications.rejected'))
                            ->send();
                    })
                    ->visible(fn (?EmployeeAdvance $record): bool => $record !== null
                        && $record->status === AdvanceStatus::Requested
                        && (Auth::user()?->can('hr.manage') ?? false))
                    ->disabled(fn (?EmployeeAdvance $record): bool => $record === null
                        || $record->status !== AdvanceStatus::Requested
                        || ! (Auth::user()?->can('hr.manage') ?? false)),

                ViewAction::make(),
                EditAction::make(),
            ])
            // No bulk actions: money records are never bulk-deleted.
            ->bulkActions([])
            ->defaultSort('advanced_on', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeAdvances::route('/'),
            'create' => Pages\CreateEmployeeAdvance::route('/create'),
            'view' => Pages\ViewEmployeeAdvance::route('/{record}'),
            'edit' => Pages\EditEmployeeAdvance::route('/{record}/edit'),
        ];
    }
}
