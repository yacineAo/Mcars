<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Amended copies of this contract, newest last.
 *
 * An amendment does not rewrite the original — `ContractService::amend()` creates a
 * child contract carrying the merged snapshot, so the record of what the customer
 * originally accepted survives (ADR-005).
 */
class ChildContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'childContracts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('contracts.sections.amendments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract_number')->label(__('contracts.fields.contract_number')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('generated_at')->label(__('Generated'))->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
