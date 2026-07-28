<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\PayrollStatus;
use App\Filament\Admin\Resources\PayrollRunResource\Pages;
use App\Models\PayrollRun;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('period_month')->required(),
                Select::make('status')->options(['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'cancelled' => 'Cancelled'])->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_month')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('approvedBy.name')->label('Approved By'),
                TextColumn::make('approved_at')->dateTime(),
                TextColumn::make('paid_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid']),
            ])
            ->actions([
                // Approve accrues gross salary, employer contributions and
                // commissions as payables; pay clears them against cash. Keeping
                // them separate is what lets the business see what it owes staff
                // before payday.
                Action::make('approve')
                    ->label(__('payroll.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(__('payroll.actions.approve_description'))
                    ->action(function (PayrollRun $record, PaymentService $payments): void {
                        // Posting and the status change must land together. Split
                        // apart, a failure between them leaves the accrual on the
                        // ledger with the run still Draft — and the UI then offers
                        // neither Approve (already posted) nor Pay (not Approved).
                        DB::transaction(function () use ($record, $payments): void {
                            $payments->approvePayroll($record, (int) Auth::id());
                            $record->update(['status' => PayrollStatus::Approved]);
                        });

                        Notification::make()
                            ->success()
                            ->title(__('payroll.notifications.approved'))
                            ->send();
                    })
                    ->visible(fn (PayrollRun $record): bool => ! $record->isPostedToLedger()),

                Action::make('pay')
                    ->label(__('payroll.actions.pay'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('payroll.actions.pay_description'))
                    ->action(function (PayrollRun $record, PaymentService $payments): void {
                        DB::transaction(function () use ($record, $payments): void {
                            $payments->payPayroll($record, (int) Auth::id());
                            $record->update(['status' => PayrollStatus::Paid]);
                        });

                        Notification::make()
                            ->success()
                            ->title(__('payroll.notifications.paid'))
                            ->send();
                    })
                    ->visible(fn (PayrollRun $record): bool => $record->status === PayrollStatus::Approved),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('period_month', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollRuns::route('/'),
            'create' => Pages\CreatePayrollRun::route('/create'),
            'edit' => Pages\EditPayrollRun::route('/{record}/edit'),
        ];
    }
}
