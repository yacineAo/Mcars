<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fine history for the car, REQ-14. Read-only: liability decides whether a fine hits profit
 * (E50) or merely passes through to the customer (E49), so it is assigned on FineResource
 * where the posting happens — never edited from the car page.
 */
class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Fines');
    }

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
                TextColumn::make('notice_number')
                    ->label('Notice')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('violation_at')
                    ->label('Violation')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('liability')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FineStatus::options()),
                SelectFilter::make('liability')
                    ->options(FineLiability::options()),
            ])
            ->defaultSort('violation_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
