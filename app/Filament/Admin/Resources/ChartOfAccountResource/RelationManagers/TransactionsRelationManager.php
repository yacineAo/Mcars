<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChartOfAccountResource\RelationManagers;

use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * Everything posted to this account, on either leg — strictly read-only
 * (ADR-003). A postings table matches debit OR credit, which is a query, not
 * a plain hasMany.
 *
 * A chart-of-account code (e.g. 5040) is shared across every branch, so unlike
 * a single financial account's own transactions, this list can span branches
 * the viewer cannot otherwise reach — `reports.view_financials` (Accountant)
 * does not imply `branches.view_all`. Pinned the same way Commission,
 * EmployeeAdvance and PaymentSchedule already pin their own record pages.
 */
class TransactionsRelationManager extends RelationManager
{
    use ChecksBranchAccess;

    protected static string $relationship = 'transactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    /** @return HasMany<Transaction, ChartOfAccount>|Builder<Transaction> */
    public function getRelationship(): Relation|Builder
    {
        $record = $this->getOwnerRecord();
        assert($record instanceof ChartOfAccount);

        return $record->hasMany(Transaction::class, 'debit_account_id');
    }

    public function table(Table $table): Table
    {
        $record = $this->getOwnerRecord();
        assert($record instanceof ChartOfAccount);

        return $table
            ->query(
                self::pinToAccessibleBranches(
                    Transaction::query()
                        ->where(function ($query) use ($record): void {
                            $query->where('debit_account_id', $record->id)
                                ->orWhere('credit_account_id', $record->id);
                        }),
                )->with(['debitAccount', 'creditAccount']),
            )
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
