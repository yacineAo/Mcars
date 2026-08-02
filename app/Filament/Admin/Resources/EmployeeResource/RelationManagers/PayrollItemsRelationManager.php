<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The payroll lines this employee was paid in, strictly read-only: the net of
 * a historical run is explained from the run's own numbers, not from the
 * employee's current record (which is why base_salary changes are not
 * effective-dated — the history is the run, and LogsActivity records the
 * change). Gated on hr.view_salary alongside the salary field itself.
 */
class PayrollItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'payrollItems';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('hr.view_salary') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollRun.period_month')
                    ->label(__('employees.relations.payroll_period'))
                    ->date('Y-m')
                    ->sortable(),
                TextColumn::make('base_salary')
                    ->label(__('employees.relations.base'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('commissions_amount')
                    ->label(__('employees.relations.commissions'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('advances_deducted')
                    ->label(__('employees.relations.advances_recovered'))
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('net_amount')
                    ->label(__('employees.relations.net'))
                    ->money('DZD')
                    ->sortable(),
            ])
            ->defaultSort('payrollRun.period_month', 'desc')
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
