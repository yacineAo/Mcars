<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\BookingStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract history, REQ-02. Read-only: a booking is created through BookingResource,
 * where the availability and pricing rules live. Nothing here writes.
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Bookings');
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('customer'))
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('pickup_at')
                    ->label('Pickup')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('expected_return_at')
                    ->label('Return')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('days_count')
                    ->label('Days')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('DZD')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(BookingStatus::options()),
            ])
            ->defaultSort('pickup_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
