<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExpenseResource\Pages;

use App\Enums\ExpenseStatus;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\ExpensePoster;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->requiresConfirmation()
                ->action(function (Expense $record, AccountingService $accounting, ExpensePoster $poster): void {
                    $record->status = ExpenseStatus::Approved;
                    $record->approved_by_id = auth()->id();
                    $record->approved_at = now();
                    $record->save();
                })
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::PendingApproval),

            Action::make('pay')
                ->label('Pay & Post')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->form([
                    Select::make('financial_account_id')
                        ->label('Pay from')
                        ->options(FinancialAccount::query()->where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Expense $record, array $data, AccountingService $accounting, ExpensePoster $poster): void {
                    DB::transaction(function () use ($record, $data, $accounting, $poster): void {
                        $account = FinancialAccount::findOrFail($data['financial_account_id']);

                        $draft = $poster->postImmediateExpense($record, $account, auth()->id());
                        $transaction = $accounting->post($draft);

                        $record->status = ExpenseStatus::Paid;
                        $record->payment_method = $record->payment_method;
                        $record->financial_account_id = $account->id;
                        $record->paid_at = now();
                        $record->transaction_id = $transaction->id;
                        $record->save();
                    });

                    Notification::make()
                        ->success()
                        ->title('Expense paid and posted to ledger')
                        ->send();
                })
                ->visible(fn (Expense $record): bool =>
                    $record->status === ExpenseStatus::Approved && $record->payment_method !== null
                ),

            Action::make('submit_for_approval')
                ->label('Submit for Approval')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->action(function (Expense $record): void {
                    $record->status = ExpenseStatus::PendingApproval;
                    $record->save();
                })
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Draft),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    \Filament\Forms\Components\Textarea::make('rejection_reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (Expense $record, array $data): void {
                    $record->status = ExpenseStatus::Rejected;
                    $record->rejection_reason = $data['rejection_reason'];
                    $record->save();
                })
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::PendingApproval),
        ];
    }
}
