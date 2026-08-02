<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayrollRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The run's ledger rows, strictly read-only: the postings are evidence of what
 * was approved and paid, and nothing here may ever be edited, deleted or
 * re-posted from this surface.
 *
 * The relationship is HasLedgerPostings' — every transaction with
 * source_type='payroll_run' and this run's id, which is exactly the batch the
 * approve/pay actions posted.
 */
class PayrollTransactionsRelationManager extends RelationManager
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
                    ->label(__('payroll.transactions.reference'))
                    ->searchable(),
                TextColumn::make('occurred_on')
                    ->label(__('payroll.transactions.occurred_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('payroll.transactions.description'))
                    ->limit(50),
                TextColumn::make('debitAccount.name')
                    ->label(__('payroll.transactions.debit')),
                TextColumn::make('creditAccount.name')
                    ->label(__('payroll.transactions.credit')),
                TextColumn::make('amount')
                    ->label(__('payroll.transactions.amount'))
                    ->money('DZD')
                    ->sortable(),
            ])
            ->defaultSort('occurred_on', 'desc');
    }
}
