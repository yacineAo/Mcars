<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers;

use App\Models\ChartOfAccount;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Payments made against this instalment: the ledger rows that debit 2200
 * (E34 cash, E35 transfer/CCP). The credit account names the method — Main
 * Cash Box, CCP Account, BaridiMob Wallet. Strictly read-only (ADR-003) and
 * gated on reports.view_financials.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerTransactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('owner_installments.relations.payments');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        $ownerPayable = ChartOfAccount::where('code', '2200')->value('id');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('debit_account_id', $ownerPayable)
                // E36 (waived) also debits 2200 but is not a payment — it is
                // the reversal-style write-off, excluded here so the list
                // shows only money actually paid out. Every posted row carries
                // a meta.group_uuid, so "meta IS NULL" would wrongly exclude
                // real payments — test the `waived` key, not the whole meta.
                ->where(function (Builder $query): Builder {
                    return $query
                        ->whereNull('meta->waived')
                        ->orWhere('meta->waived', '!=', 'true');
                })
                ->with('creditAccount'))
            ->columns([
                TextColumn::make('occurred_on')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('creditAccount.name')
                    ->label(__('Method')),
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->money('DZD')
                    ->sortable(),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }
}
