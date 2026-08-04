<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CommissionStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CommissionResource\Pages;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Employee;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CommissionResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Commission::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

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
        return $record instanceof Commission && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (Auth::user()?->can('hr.manage') ?? false)
            && $record instanceof Commission
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // A commission is money: it either sits in the unpaid sweep queue or
        // was paid through payroll (E59) — neither is deleted. An unwanted one
        // is cancelled through the payroll flow.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        // Once the payroll run swept the commission in (payroll_item_id), the
        // money has moved through E59: employee, booking, basis, rate and date
        // are frozen, because changing any of them afterwards would leave the
        // commission and its posting disagreeing. Before that, correcting the
        // rate is legitimate.
        $frozenOncePaid = fn (?Commission $record): bool => $record !== null
            && ($record->payroll_item_id !== null || $record->status === CommissionStatus::Paid);

        return $schema
            ->schema([
                Select::make('employee_id')
                    ->label(__('commissions.fields.employee'))
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
                    ->preload()
                    ->required()
                    // Self-dealing is refused at the form: a commission on your
                    // own employee record is never worth typing. CommissionService
                    // re-checks it at write time.
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (Employee::whereKey($value)->value('user_id') === auth()->id()) {
                                $fail(__('commissions.validation.self_granted'));
                            }
                        },
                    ])
                    ->disabled($frozenOncePaid),
                Select::make('booking_id')
                    ->label(__('commissions.fields.booking'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Booking::query())
                        ->orderByDesc('pickup_at')
                        ->get()
                        ->mapWithKeys(fn (Booking $booking): array => [
                            $booking->id => $booking->reference,
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled($frozenOncePaid),
                TextInput::make('basis_amount')
                    ->label(__('commissions.fields.basis_amount'))
                    // The amount is not typed anywhere: CommissionService computes
                    // it from basis × rate in integer minor units, so the record
                    // always agrees with its own basis and rate.
                    ->helperText(__('commissions.fields.basis_amount_help'))
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->disabled($frozenOncePaid),
                TextInput::make('rate')
                    ->label(__('commissions.fields.rate'))
                    ->numeric()
                    ->required()
                    ->suffix('%')
                    ->disabled($frozenOncePaid),
                DatePicker::make('earned_on')
                    ->label(__('commissions.fields.earned_on'))
                    ->required()
                    ->disabled($frozenOncePaid),
                Textarea::make('notes')
                    ->label(__('commissions.fields.notes'))
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label(__('commissions.columns.employee'))
                    ->searchable(),
                TextColumn::make('booking.reference')
                    ->label(__('commissions.columns.booking'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('earned_on')
                    ->label(__('commissions.columns.earned_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('basis_amount')
                    ->label(__('commissions.columns.basis'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('rate')
                    ->label(__('commissions.columns.rate'))
                    ->suffix('%'),
                TextColumn::make('amount')
                    ->label(__('commissions.columns.amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('commissions.columns.status'))
                    ->badge(),
                // Derived from the sweep stamp: null means still owed to the
                // employee. The stamp is written by the payroll run that pays it.
                TextColumn::make('payrollItem.payrollRun.period_month')
                    ->label(__('commissions.columns.swept_in'))
                    ->date('Y-m')
                    ->placeholder('—'),
            ])
            ->filters([
                // The sweep queue: commissions not yet in any payroll run. This
                // is the only filter that matters at month-end; hide everything
                // already paid unless asked.
                TernaryFilter::make('unpaid')
                    ->label(__('commissions.filters.unpaid'))
                    ->default(true)
                    ->trueLabel(__('commissions.filters.unpaid'))
                    ->falseLabel(__('commissions.filters.swept'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('payroll_item_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('payroll_item_id'),
                    ),
                SelectFilter::make('employee_id')
                    ->label(__('commissions.filters.employee'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Employee::query())
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => $employee->first_name.' '.$employee->last_name,
                        ])
                        ->all()),
                Filter::make('earned_on_range')
                    ->label(__('commissions.filters.earned_on'))
                    ->schema([
                        DatePicker::make('earned_from')->label(__('commissions.filters.earned_from')),
                        DatePicker::make('earned_until')->label(__('commissions.filters.earned_until')),
                    ])
                    // `earned_on` is a plain date column: inclusive bounds on
                    // the date itself, no timezone handling needed.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['earned_from'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->whereDate('earned_on', '>=', $date))
                        ->when($data['earned_until'] ?? null, fn (Builder $q, string $date): Builder => $q
                            ->whereDate('earned_on', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                // The lifecycle (approved, paid, cancelled) moves with the
                // payroll flow — see CommissionService — so the only row action
                // here is the paperwork correction the Edit screen allows.
                EditAction::make(),
            ])
            // No bulk actions: money records are never bulk-deleted.
            ->bulkActions([])
            ->defaultSort('earned_on', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
            'create' => Pages\CreateCommission::route('/create'),
            'view' => Pages\ViewCommission::route('/{record}'),
            'edit' => Pages\EditCommission::route('/{record}/edit'),
        ];
    }
}
