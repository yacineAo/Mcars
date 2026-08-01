<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DepositResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The drawn-down part of the deposit, strictly read-only.
 *
 * Rows are created by the `deduct` action through DepositService, which refuses
 * a deduction that would exceed the deposit. A create form here would bypass
 * that cap entirely — this is the one place on this screen where an
 * innocent-looking "add" button would break the invariant.
 */
class DeductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'deductions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            // The "Created by" column resolves a relation per row without this.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('createdBy'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->badge(),
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('createdBy.name')
                    ->label(__('Created by'))
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
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
