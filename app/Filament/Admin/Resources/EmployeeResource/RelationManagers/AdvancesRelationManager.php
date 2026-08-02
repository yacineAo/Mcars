<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The advances this employee took and where each was recovered — read-only,
 * gated on hr.view_salary like every other pay screen.
 */
class AdvancesRelationManager extends RelationManager
{
    protected static string $relationship = 'advances';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('hr.view_salary') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('advanced_on')
                    ->label(__('employees.relations.advanced_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('employees.relations.amount'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('employees.relations.status'))
                    ->badge(),
                TextColumn::make('recoveredInPayrollItem.payrollRun.period_month')
                    ->label(__('employees.relations.recovered_in'))
                    ->date('Y-m')
                    ->placeholder('—'),
            ])
            ->defaultSort('advanced_on', 'desc')
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
