<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\BlockReason;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Non-booking unavailability. Read-only on purpose: a block is written by the `block_car`
 * action through BlockCarService, which validates the window against bookings and other
 * blocks. Editing a row here would bypass that check — and `BookingAvailabilityService`
 * reads these rows, so a bad block silently changes what the booking path believes.
 */
class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Blocks');
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
                TextColumn::make('reason')
                    ->badge()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('From')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Until')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('notes')
                    ->limit(60)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(BlockReason::options()),
            ])
            ->defaultSort('starts_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
