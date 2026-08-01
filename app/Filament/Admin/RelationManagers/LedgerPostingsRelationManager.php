<?php

declare(strict_types=1);

namespace App\Filament\Admin\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The ledger rows a business document produced, strictly read-only (ADR-003).
 *
 * Payment, Expense and Deposit each showed the same eight columns over the same
 * `ledgerTransactions` relation, in three byte-identical classes. One copy means
 * the eager load below — `debitAccount` and `creditAccount` are resolved per row,
 * so a document with a dozen postings cost two dozen extra queries — is fixed
 * once rather than found three times.
 *
 * A subclass supplies nothing but its namespace. Anything that needs different
 * columns or a different relation (CashSession, FinancialAccount) is a different
 * screen and stays its own class.
 */
abstract class LedgerPostingsRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerTransactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['debitAccount', 'creditAccount']))
            ->columns([
                TextColumn::make('reference')
                    ->searchable(),
                TextColumn::make('occurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('description')
                    ->limit(60),
                TextColumn::make('debitAccount.name')
                    ->label(__('Debit')),
                TextColumn::make('creditAccount.name')
                    ->label(__('Credit')),
                TextColumn::make('amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('is_reversal')
                    ->label(__('Reversal'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
            ])
            ->defaultSort('occurred_on', 'asc')
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
