<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('car.brand')
                    ->label(__('Car'))
                    ->formatStateUsing(fn ($state, $record): string => $record->car ? trim($record->car->brand.' '.$record->car->model) : ''),
                TextColumn::make('pickup_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expected_return_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('DZD')
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
