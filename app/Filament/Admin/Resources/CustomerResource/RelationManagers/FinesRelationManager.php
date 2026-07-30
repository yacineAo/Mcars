<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notice_number')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('violation_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('liability')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
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
