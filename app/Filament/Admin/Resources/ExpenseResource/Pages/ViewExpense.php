<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExpenseResource\Pages;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\ExpenseResource\RelationManagers\TransactionsRelationManager;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use RuntimeException;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        $canRecord = auth()->user()?->can('expenses.record') ?? false;
        $canApprove = auth()->user()?->can('expenses.approve') ?? false;
        $canPay = auth()->user()?->can('expenses.pay') ?? false;

        return [
            Action::make('submit_for_approval')
                ->label('Submit for Approval')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->action(function (Expense $record, ExpenseService $service): void {
                    try {
                        $service->submitForApproval($record, auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->danger()
                            ->title($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('Expense submitted for approval'))
                        ->send();
                })
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Draft && $canRecord),

            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->requiresConfirmation()
                ->action(function (Expense $record, ExpenseService $service): void {
                    try {
                        $service->approve($record, auth()->user());
                    } catch (RuntimeException $e) {
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
                ->visible(fn (Expense $record): bool => in_array($record->status, [ExpenseStatus::Draft, ExpenseStatus::PendingApproval], true) && $canApprove),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('rejection_reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (Expense $record, array $data, ExpenseService $service): void {
                    try {
                        $service->reject($record, (string) $data['rejection_reason'], auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->danger()
                            ->title($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('Expense rejected'))
                        ->send();
                })
                ->visible(fn (Expense $record): bool => in_array($record->status, [ExpenseStatus::Draft, ExpenseStatus::PendingApproval], true) && $canApprove),

            Action::make('pay')
                ->label('Pay & Post')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->form([
                    Select::make('payment_method')
                        ->label('Payment Method')
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
                        ->preload()
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
                        ->title(__('Expense paid and posted to ledger'))
                        ->send();
                })
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Approved && $canPay),
        ];
    }

    /**
     * The expense's ledger postings: the E39 entry that paid it and anything
     * that reverses it, strictly read-only (ADR-003). A reversed expense must
     * show its reversal here, or it would look paid and correct.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Postings'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
