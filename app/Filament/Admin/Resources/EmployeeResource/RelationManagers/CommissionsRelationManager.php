<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The commissions this employee earned on bookings — read-only, gated on
 * hr.view_salary like every other pay screen.
 */
class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('hr.view_salary') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('earned_on')
                    ->label(__('employees.relations.earned_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('booking.reference')
                    ->label(__('employees.relations.booking'))
                    ->placeholder('—'),
                TextColumn::make('basis_amount')
                    ->label(__('employees.relations.basis'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('rate')
                    ->label(__('employees.relations.rate'))
                    ->suffix('%'),
                TextColumn::make('amount')
                    ->label(__('employees.relations.amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('employees.relations.status'))
                    ->badge(),
            ])
            ->defaultSort('earned_on', 'desc')
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
