<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerTransactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
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
