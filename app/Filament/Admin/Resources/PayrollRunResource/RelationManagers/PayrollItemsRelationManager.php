<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayrollRunResource\RelationManagers;

use App\Enums\PayrollStatus;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\Payment\PayrollService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The run's lines. Read-only once the run is approved — the posting exists,
 * and the batch must stay exactly what was approved — but while it is still a
 * draft, adjusting an item is a legitimate correction (the same condition the
 * resource's canEdit enforces). Every column is money, so the whole surface
 * sits behind reports.view_financials like every other money view.
 */
class PayrollItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_name')
                    ->label(__('payroll.items.employee'))
                    ->getStateUsing(fn (PayrollItem $record): string => trim(
                        ($record->employee->first_name ?? '').' '.($record->employee->last_name ?? ''),
                    )),
                TextColumn::make('employee.employee_number')
                    ->label(__('payroll.items.employee_number'))
                    ->placeholder('—'),
                TextColumn::make('base_salary')
                    ->label(__('payroll.items.base'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('commissions_amount')
                    ->label(__('payroll.items.commissions'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('advances_deducted')
                    ->label(__('payroll.items.advances'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('net_amount')
                    ->label(__('payroll.items.net'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('payroll.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'gray'),
            ])
            ->recordActions([
                // Corrections live in a modal so the run page never leaves; the
                // save recomputes gross and net from the edited terms so the
                // posting can never disagree with the batch. The explicit
                // authorize() replaces the default policy check, which would
                // deny an item record that has no policy.
                EditAction::make()
                    ->label(__('payroll.items.edit'))
                    ->authorize(fn (): bool => Auth::user()?->can('hr.manage') ?? false)
                    ->form([
                        TextInput::make('base_salary')
                            ->label(__('payroll.items.base'))
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        TextInput::make('bonuses_amount')
                            ->label(__('payroll.items.bonuses'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('overtime_amount')
                            ->label(__('payroll.items.overtime'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('commissions_amount')
                            ->label(__('payroll.items.commissions'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('advances_deducted')
                            ->label(__('payroll.items.advances'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('absences_deduction')
                            ->label(__('payroll.items.absences'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('social_contributions')
                            ->label(__('payroll.items.social'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('other_deductions')
                            ->label(__('payroll.items.other'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->using(function (PayrollItem $record, array $data): PayrollItem {
                        $gross = Money::of((string) $data['base_salary'])
                            ->plus(Money::of((string) $data['bonuses_amount']))
                            ->plus(Money::of((string) $data['overtime_amount']));
                        $net = $gross
                            ->plus(Money::of((string) $data['commissions_amount']))
                            ->minus(Money::of((string) $data['advances_deducted']))
                            ->minus(Money::of((string) $data['absences_deduction']))
                            ->minus(Money::of((string) $data['other_deductions']));

                        $record->update([
                            ...$data,
                            'gross_amount' => $gross->toDecimal(),
                            'net_amount' => $net->toDecimal(),
                        ]);

                        return $record;
                    })
                    ->visible(fn (): bool => $this->editable()),
                // Removing a draft item releases its claimed commission and
                // advance back to the sweep queues (unsweep) — a correction
                // must never bury money with the line.
                Action::make('remove')
                    ->label(__('payroll.items.remove'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (PayrollItem $record): void {
                        app(PayrollService::class)->unsweep($record);

                        Notification::make()
                            ->success()
                            ->title(__('payroll.notifications.item_removed'))
                            ->send();
                    })
                    ->authorize(fn (): bool => Auth::user()?->can('hr.manage') ?? false)
                    ->visible(fn (): bool => $this->editable()),
            ])
            ->defaultSort('id');
    }

    /**
     * Corrections are legitimate while the run is still a draft; once it is
     * approved the batch is exactly what the postings say it is.
     */
    private function editable(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof PayrollRun && $owner->status === PayrollStatus::Draft;
    }
}
