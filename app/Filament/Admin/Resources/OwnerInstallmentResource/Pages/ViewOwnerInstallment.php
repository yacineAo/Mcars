<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OwnerInstallmentResource\Pages;

use App\Filament\Admin\Resources\CarOwnerResource;
use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\OwnerInstallmentResource;
use App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers\TransactionsRelationManager;
use App\Models\OwnerInstallment;
use App\Services\ReportService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The single-installment owner statement: what the month owed, what E32
 * recorded, and what has been paid out against 2200 since.
 */
class ViewOwnerInstallment extends ViewRecord
{
    protected static string $resource = OwnerInstallmentResource::class;

    public function infolist(Schema $schema): Schema
    {
        $reports = app(ReportService::class);

        return $schema
            ->schema([
                Section::make(__('Money'))
                    ->schema([
                        TextEntry::make('amount_due')
                            ->label(__('owner_installments.fields.amount_due'))
                            ->money('DZD')
                            ->weight('bold'),
                        // Derived from the ledger (ADR-003): E34/E35 rows that
                        // debit 2200 against this instalment.
                        TextEntry::make('paid')
                            ->label(__('owner_installments.fields.paid'))
                            ->state(fn (OwnerInstallment $record): float => $reports->installmentPaidAmount((int) $record->id))
                            ->money('DZD'),
                        TextEntry::make('status')
                            ->label(__('owner_installments.fields.status'))
                            ->badge(),
                        TextEntry::make('accrual_transaction_id')
                            ->label(__('owner_installments.fields.accrued'))
                            ->state(fn (OwnerInstallment $record): string => $record->isPostedToLedger()
                                ? __('owner_installments.fields.accrued')
                                : __('owner_installments.fields.not_accrued'))
                            ->badge()
                            ->color(fn (OwnerInstallment $record): string => $record->isPostedToLedger() ? 'success' : 'warning'),
                    ])
                    ->columns(4),

                Section::make(__('Period'))
                    ->schema([
                        TextEntry::make('period_month')
                            ->label(__('owner_installments.fields.period_month'))
                            ->date('Y-m'),
                        TextEntry::make('due_date')
                            ->label(__('owner_installments.fields.due_date'))
                            ->date(),
                        TextEntry::make('sequence_number')->label(__('Sequence')),
                        TextEntry::make('branch.name')->label(__('Branch'))->placeholder('—'),
                    ])
                    ->columns(4),

                Section::make(__('Owner & Car'))
                    ->schema([
                        TextEntry::make('car_owner_id')
                            ->label(__('owner_installments.fields.owner'))
                            ->formatStateUsing(fn (OwnerInstallment $record): string => $record->carOwner !== null
                                ? trim($record->carOwner->first_name.' '.$record->carOwner->last_name)
                                : '—')
                            ->url(fn (OwnerInstallment $record): ?string => $record->carOwner !== null && CarOwnerResource::canAccess()
                                ? CarOwnerResource::getUrl('view', ['record' => $record->carOwner])
                                : null),
                        TextEntry::make('car_id')
                            ->label(__('owner_installments.fields.car'))
                            ->formatStateUsing(fn (OwnerInstallment $record): string => $record->car->registration_number ?? '—')
                            ->url(fn (OwnerInstallment $record): ?string => $record->car !== null && CarResource::canAccess()
                                ? CarResource::getUrl('view', ['record' => $record->car])
                                : null),
                        TextEntry::make('agreement_id')
                            ->label(__('owner_installments.fields.agreement'))
                            ->state(fn (OwnerInstallment $record): string => $record->agreement->car->registration_number ?? '—'),
                    ])
                    ->columns(3),

                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('waived_reason')
                            ->label(__('owner_installments.fields.waived_reason'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * The instalment's ledger history: the E32 accrual and any E34/E35
     * payments against it. Both strictly read-only (ADR-003) and gated on
     * reports.view_financials.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('History'), [
                TransactionsRelationManager::class,
                PaymentsRelationManager::class,
            ]),
        ];
    }
}
