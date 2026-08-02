<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\PayrollStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\PayrollRunResource\Pages;
use App\Models\Branch;
use App\Models\PayrollRun;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PayrollService;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PayrollRunResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    /**
     * The run holds salaries — the same confidentiality gate as every salary
     * surface: view_salary (manager, accountant), writes behind hr.manage.
     */
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
        return $record instanceof PayrollRun && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (Auth::user()?->can('hr.manage') ?? false)
            && $record instanceof PayrollRun
            && $record->status === PayrollStatus::Draft
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // Once the run is generated its items have claimed commissions and
        // advances from the sweep queues, and once approved the month is on
        // the ledger — neither can be deleted. There is no delete path at all.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        // No status field on purpose: the run is generated as a draft by
        // PayrollService and moves to approved/paid only through the posting
        // flow — the form cannot manufacture a status the ledger disagrees with.
        return $schema
            ->schema([
                DatePicker::make('period_month')
                    ->label(__('payroll.fields.period_month'))
                    ->displayFormat('Y-m')
                    ->required(),
                Textarea::make('notes')
                    ->label(__('payroll.fields.notes'))
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_month')
                    ->label(__('payroll.fields.period_month'))
                    ->date('Y-m')
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label(__('payroll.fields.branch'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('payroll.fields.status'))
                    ->badge(),
                TextColumn::make('net_total')
                    ->label(__('payroll.fields.total'))
                    ->money('DZD')
                    ->state(fn (PayrollRun $record): string => app(PayrollService::class)
                        ->totalNetFor($record)
                        ->toDecimal()),
                TextColumn::make('items_count')
                    ->label(__('payroll.fields.employees'))
                    ->counts('items'),
                TextColumn::make('approvedBy.name')
                    ->label(__('payroll.fields.approved_by'))
                    ->placeholder('—'),
                TextColumn::make('approved_at')
                    ->label(__('payroll.fields.approved_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('paid_at')
                    ->label(__('payroll.fields.paid_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                // Unpaid months first: draft and approved runs are the work
                // queue, paid runs are history.
                SelectFilter::make('status')
                    ->label(__('payroll.filters.status'))
                    ->multiple()
                    ->options(PayrollStatus::options())
                    ->default([PayrollStatus::Draft->value, PayrollStatus::Approved->value]),
                SelectFilter::make('period_month')
                    ->label(__('payroll.filters.period'))
                    ->options(fn (): array => PayrollRun::query()
                        ->distinct()
                        ->orderByDesc('period_month')
                        ->pluck('period_month')
                        ->mapWithKeys(fn (string $month): array => [
                            $month => CarbonImmutable::parse($month)->format('Y-m'),
                        ])
                        ->all()),
                SelectFilter::make('branch_id')
                    ->label(__('payroll.filters.branch'))
                    ->options(fn (): array => self::pinToAccessibleBranches(Branch::query(), 'id')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([
                // Approve accrues gross salary, employer contributions and
                // commissions as payables; pay clears them against cash.
                // Keeping them separate is what lets the business see what it
                // owes staff before payday — see PaymentService::approvePayroll
                // for the guard that makes the flip atomic with the posting.
                Action::make('approve')
                    ->label(__('payroll.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(__('payroll.actions.approve_description'))
                    ->action(function (PayrollRun $record, PaymentService $payments): void {
                        try {
                            $payments->approvePayroll($record, (int) Auth::id());
                        } catch (DomainException $e) {
                            // A stale row (already approved, already paid)
                            // surfaces as a refusal, not a 500.
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('payroll.notifications.approved'))
                            ->send();
                    })
                    ->visible(fn (PayrollRun $record): bool => $record->status === PayrollStatus::Draft)
                    ->authorize(fn (): bool => Auth::user()?->can('hr.manage') ?? false),

                Action::make('pay')
                    ->label(__('payroll.actions.pay'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('payroll.actions.pay_description'))
                    ->action(function (PayrollRun $record, PaymentService $payments): void {
                        try {
                            $payments->payPayroll($record, (int) Auth::id());
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('payroll.notifications.paid'))
                            ->send();
                    })
                    ->visible(fn (PayrollRun $record): bool => $record->status === PayrollStatus::Approved)
                    ->authorize(fn (): bool => Auth::user()?->can('hr.manage') ?? false),

                // Frozen once approved — the posting exists. canEdit re-asserts
                // the same condition at the page mount.
                EditAction::make(),
            ])
            // No bulk actions: a payroll run is a posting in waiting, and
            // money records are never bulk-deleted.
            ->bulkActions([])
            ->defaultSort('period_month', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollRuns::route('/'),
            'create' => Pages\CreatePayrollRun::route('/create'),
            'view' => Pages\ViewPayrollRun::route('/{record}'),
            'edit' => Pages\EditPayrollRun::route('/{record}/edit'),
        ];
    }
}
