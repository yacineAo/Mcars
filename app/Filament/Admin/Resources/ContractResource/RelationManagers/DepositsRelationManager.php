<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Security deposits held against this contract's booking, read-only.
 *
 * A deposit is a liability, never revenue — holding, refunding and forfeiting it each
 * post to the ledger through DepositService, so none of them can be done by editing a
 * row here.
 */
class DepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Deposits');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount')->label(__('Amount'))->money('DZD'),
                TextColumn::make('method')->label(__('Method'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('held_at')->label(__('Held at'))->dateTime()->placeholder('—'),
                TextColumn::make('settled_at')->label(__('Settled at'))->dateTime()->placeholder('—'),
            ])
            ->defaultSort('held_at', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
