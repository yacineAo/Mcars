<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\RelationManagers;

use App\Models\FinancialAccount;
use App\Models\Transaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function getRelationship(): Relation|Builder
    {
        return $this->getOwnerRecord()->hasMany(Transaction::class, 'debit_account_id', 'ledger_account_id');
    }

    public function table(Table $table): Table
    {
        $record = $this->getOwnerRecord();
        assert($record instanceof FinancialAccount);

        return $table
            ->query(
                Transaction::query()
                    ->where(function ($q) use ($record) {
                        $q->where('debit_account_id', $record->ledger_account_id)
                            ->orWhere('credit_account_id', $record->ledger_account_id);
                    }),
            )
            ->columns([
                TextColumn::make('occurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(60),
                TextColumn::make('type'),
                TextColumn::make('amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('debitAccount.name')
                    ->label(__('Debit')),
                TextColumn::make('creditAccount.name')
                    ->label(__('Credit')),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
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

    public function isReadOnly(): bool
    {
        return true;
    }
}
