<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChartOfAccountResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The sub-accounts under this one — read-only. Renaming or reparenting a
 * child happens on the child's own edit form, never from here.
 */
class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->sortable(),
                TextColumn::make('name')
                    ->sortable(),
                TextColumn::make('type'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('code')
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
