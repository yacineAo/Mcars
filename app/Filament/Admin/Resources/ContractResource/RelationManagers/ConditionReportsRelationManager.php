<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The condition reports written against the contract's booking, read-only.
 *
 * Condition reports are written through ConditionReportService during checkout and
 * check-in — they are the evidence a contract was performed, not an editable list.
 */
class ConditionReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'conditionReports';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('contracts.sections.condition_reports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('performed_at')->label(__('Performed at'))->dateTime(),
                TextColumn::make('odometer')->label(__('Odometer'))->placeholder('—'),
                TextColumn::make('fuel_level')->label(__('Fuel level'))->badge(),
                IconColumn::make('is_clean')->label(__('Clean'))->boolean(),
            ])
            ->defaultSort('performed_at', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
