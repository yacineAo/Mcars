<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use App\Models\ConditionReport;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Check-out and check-in inspections, read-only.
 *
 * A condition report is evidence of the car's state at handover — it is what a damage
 * charge is argued from. Editing one after the fact from the booking screen would
 * undermine that, so it is created through the check-out/check-in actions only.
 */
class ConditionReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'conditionReports';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Condition Reports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label(__('Direction'))->badge(),
                TextColumn::make('performed_at')->label(__('Performed at'))->dateTime()->sortable(),
                TextColumn::make('odometer')->label(__('Odometer'))->numeric(),
                TextColumn::make('fuel_level')->label(__('Fuel level'))->badge(),
                IconColumn::make('is_clean')->label(__('Clean'))->boolean(),
                TextColumn::make('damage_points')
                    ->label(__('Damages'))
                    // The column is a jsonb array of damage points; the count is what
                    // matters in a list, and the detail belongs on the report itself.
                    ->formatStateUsing(fn ($state): int => is_array($state) ? count($state) : 0)
                    ->badge()
                    ->color(fn (ConditionReport $record): string => count($record->damage_points ?? []) > 0
                        ? 'danger'
                        : 'success'),
            ])
            ->defaultSort('performed_at', 'asc')
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
