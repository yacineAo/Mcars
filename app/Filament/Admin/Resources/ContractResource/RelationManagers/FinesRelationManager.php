<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Traffic fines linked to this contract, read-only.
 *
 * Fines are written through FineService, which posts the liability to the ledger and
 * settles it from the deposit or a payment; none of that happens by editing a row
 * here.
 */
class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Fines');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->searchable(),
                TextColumn::make('violation_at')->label(__('Violation at'))->dateTime()->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->money('DZD'),
                TextColumn::make('total_amount')->label(__('Total'))->money('DZD'),
                TextColumn::make('liability')->label(__('Liability'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
