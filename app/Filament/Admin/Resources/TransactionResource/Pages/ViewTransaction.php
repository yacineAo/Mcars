<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TransactionResource\Pages;

use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\CarDocumentResource;
use App\Filament\Admin\Resources\CarOwnerResource;
use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\CashSessionResource;
use App\Filament\Admin\Resources\ContractResource;
use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\ExpenseCategoryResource;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\FineResource;
use App\Filament\Admin\Resources\MaintenanceLogResource;
use App\Filament\Admin\Resources\OwnerInstallmentResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Filament\Admin\Resources\PayrollRunResource;
use App\Filament\Admin\Resources\TransactionResource;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')
                ->label('Reverse Transaction')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for reversal')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data, AccountingService $accounting): void {
                    /** @var Transaction $record */
                    $record = $this->getRecord();

                    if ($record->is_reversal) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot reverse a reversal')
                            ->send();

                        return;
                    }

                    $accounting->reverse($record, (string) $data['reason'], auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Transaction reversed')
                        ->body('A reversal transaction has been posted.')
                        ->send();
                })
                ->visible(fn (Transaction $record): bool => ! $record->is_reversal && auth()->user()?->can('reverse_transaction')),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Transaction'))
                    ->schema([
                        TextEntry::make('reference'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('amount')
                            ->money('DZD'),
                        TextEntry::make('occurred_on')
                            ->date(),
                        TextEntry::make('posted_at')
                            ->dateTime(),
                        TextEntry::make('createdBy.name')
                            ->label('Created By'),
                        IconEntry::make('is_reversal')
                            ->label('Reversal')
                            ->boolean(),
                        TextEntry::make('description')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('Posting'))
                    ->schema([
                        TextEntry::make('debit')
                            ->label('Debit')
                            ->state(fn (Transaction $record): ?string => $record->debitAccount ? sprintf('%s · %s', (string) $record->debitAccount->code, (string) $record->debitAccount->name) : null),
                        TextEntry::make('credit')
                            ->label('Credit')
                            ->state(fn (Transaction $record): ?string => $record->creditAccount ? sprintf('%s · %s', (string) $record->creditAccount->code, (string) $record->creditAccount->name) : null),
                        TextEntry::make('payment_method')
                            ->badge()
                            ->visible(fn (Transaction $record): bool => $record->payment_method !== null),
                    ])
                    ->columns(3),
                Section::make(__('Source Document'))
                    ->schema([
                        TextEntry::make('source')
                            ->label(__('Source'))
                            ->state(fn (Transaction $record): ?string => self::sourceLabel($record))
                            ->url(fn (Transaction $record): ?string => self::sourceUrl($record))
                            ->placeholder('—'),
                        TextEntry::make('reverses')
                            ->label(__('Reverses'))
                            ->state(fn (Transaction $record): ?string => $record->reversesTransaction?->reference)
                            ->url(fn (Transaction $record): ?string => $record->reversesTransaction ? TransactionResource::getUrl('view', ['record' => $record->reversesTransaction]) : null)
                            ->visible(fn (Transaction $record): bool => $record->reversesTransaction !== null),
                        TextEntry::make('reversed_by')
                            ->label(__('Reversed By'))
                            ->state(fn (Transaction $record): ?string => $record->reversal?->reference)
                            ->url(fn (Transaction $record): ?string => $record->reversal ? TransactionResource::getUrl('view', ['record' => $record->reversal]) : null)
                            ->visible(fn (Transaction $record): bool => $record->reversal !== null),
                    ])
                    ->columns(3),
                Section::make(__('Dimensions'))
                    ->schema([
                        TextEntry::make('branch.name')
                            ->label('Branch')
                            ->visible(fn (Transaction $record): bool => $record->branch_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->branch_id === null ? null : BranchResource::getUrl('edit', ['record' => $record->branch_id])),
                        TextEntry::make('car.registration_number')
                            ->label('Car')
                            ->visible(fn (Transaction $record): bool => $record->car_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->car_id === null ? null : CarResource::getUrl('view', ['record' => $record->car_id])),
                        TextEntry::make('customer_name')
                            ->label('Customer')
                            ->visible(fn (Transaction $record): bool => $record->customer_id !== null)
                            ->state(fn (Transaction $record): ?string => $record->customer === null ? null : self::customerName($record->customer))
                            ->url(fn (Transaction $record): ?string => $record->customer_id === null ? null : CustomerResource::getUrl('view', ['record' => $record->customer_id])),
                        TextEntry::make('carOwner.full_name')
                            ->label('Car Owner')
                            ->visible(fn (Transaction $record): bool => $record->car_owner_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->car_owner_id === null ? null : CarOwnerResource::getUrl('view', ['record' => $record->car_owner_id])),
                        TextEntry::make('booking.reference')
                            ->label('Booking')
                            ->visible(fn (Transaction $record): bool => $record->booking_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->booking_id === null ? null : BookingResource::getUrl('edit', ['record' => $record->booking_id])),
                        TextEntry::make('contract.contract_number')
                            ->label('Contract')
                            ->visible(fn (Transaction $record): bool => $record->contract_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->contract_id === null ? null : ContractResource::getUrl('edit', ['record' => $record->contract_id])),
                        TextEntry::make('cash_session_id')
                            ->label('Cash Session')
                            ->visible(fn (Transaction $record): bool => $record->cash_session_id !== null)
                            ->state(fn (Transaction $record): ?string => $record->cash_session_id === null ? null : sprintf('#%s', $record->cash_session_id))
                            ->url(fn (Transaction $record): ?string => $record->cash_session_id === null ? null : CashSessionResource::getUrl('view', ['record' => $record->cash_session_id])),
                        TextEntry::make('expense_category.name')
                            ->label('Expense Category')
                            ->visible(fn (Transaction $record): bool => $record->expense_category_id !== null)
                            ->url(fn (Transaction $record): ?string => $record->expense_category_id === null ? null : ExpenseCategoryResource::getUrl('edit', ['record' => $record->expense_category_id])),
                    ])
                    ->columns(3),
            ]);
    }

    /** @return array<string, class-string<\Filament\Resources\Resource>> */
    private static function sourceResources(): array
    {
        return [
            'booking' => BookingResource::class,
            'car_document' => CarDocumentResource::class,
            'cash_session' => CashSessionResource::class,
            'deposit' => DepositResource::class,
            'expense' => ExpenseResource::class,
            'fine' => FineResource::class,
            'maintenance_log' => MaintenanceLogResource::class,
            'owner_installment' => OwnerInstallmentResource::class,
            'payment' => PaymentResource::class,
            'payroll_run' => PayrollRunResource::class,
        ];
    }

    private static function sourceLabel(Transaction $record): ?string
    {
        if ($record->source_type === null || $record->source_id === null) {
            return null;
        }

        return sprintf('%s #%s', Str::headline((string) $record->source_type), (string) $record->source_id);
    }

    private static function sourceUrl(Transaction $record): ?string
    {
        $resource = self::sourceResources()[$record->source_type] ?? null;

        if ($resource === null || $record->source_id === null) {
            return null;
        }

        return $resource::getUrl($record->source_type === 'expense' ? 'view' : 'edit', ['record' => $record->source_id]);
    }

    private static function customerName(Customer $customer): string
    {
        if ((string) $customer->company_name !== '') {
            return (string) $customer->company_name;
        }

        return trim(implode(' ', array_filter([
            $customer->first_name,
            $customer->last_name,
        ])));
    }
}
