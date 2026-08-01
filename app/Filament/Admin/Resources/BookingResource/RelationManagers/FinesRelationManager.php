<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Traffic fines attached to this booking, read-only.
 *
 * Not gated on `reports.view_financials`: a receptionist taking the car back needs to
 * know a fine arrived against it, and deciding who is liable is FineResource's job.
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
                TextColumn::make('notice_number')->label(__('Notice number'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('violation_at')->label(__('Violation at'))->dateTime()->sortable(),
                TextColumn::make('total_amount')->label(__('Amount'))->money('DZD'),
                TextColumn::make('liability')->label(__('Liability'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('violation_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([]);
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
