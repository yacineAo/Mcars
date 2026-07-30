<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\Pages;

use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Filament\Admin\Resources\FinancialAccountResource\RelationManagers\CashSessionsRelationManager;
use App\Filament\Admin\Resources\FinancialAccountResource\RelationManagers\TransactionsRelationManager;
use App\Models\FinancialAccount;
use App\Services\CashRegisterService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewFinancialAccount extends ViewRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Account Details'))
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('ledgerAccount.name')
                            ->label('Ledger Account'),
                        TextEntry::make('branch.name')
                            ->label('Branch'),
                        TextEntry::make('opening_balance')
                            ->money('DZD'),
                        TextEntry::make('opened_on')
                            ->date(),
                        TextEntry::make('currency'),
                        IconEntry::make('is_default_for_cash')
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->boolean(),
                    ])
                    ->columns(3),
                Section::make(__('Bank Details'))
                    ->visible(fn (FinancialAccount $record): bool => $record->account_number !== null || $record->rib !== null || $record->holder_name !== null)
                    ->schema([
                        TextEntry::make('account_number'),
                        TextEntry::make('rib'),
                        TextEntry::make('holder_name'),
                    ])
                    ->columns(2),
                Section::make(__('Current Balance'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('current_balance_display')
                            ->label(__('Balance'))
                            ->state(function (FinancialAccount $record): string {
                                $service = app(CashRegisterService::class);
                                $balance = $service->balanceOf($record);

                                return $balance->format().' DZD';
                            }),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Transactions'), [
                TransactionsRelationManager::class,
            ]),
            RelationGroup::make(__('Cash Sessions'), [
                CashSessionsRelationManager::class,
            ]),
        ];
    }
}
