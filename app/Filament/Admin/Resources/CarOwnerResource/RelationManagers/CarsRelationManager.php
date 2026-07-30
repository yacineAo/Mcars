<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\RelationManagers;

use App\Enums\CarStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CarsRelationManager extends RelationManager
{
    protected static string $relationship = 'cars';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_number')
                    ->label('Plate')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('model')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('daily_rate')
                    ->label('Daily Rate')
                    ->money('DZD')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CarStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
