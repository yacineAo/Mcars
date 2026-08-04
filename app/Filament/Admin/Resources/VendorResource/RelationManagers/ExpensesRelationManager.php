<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\RelationManagers;

use App\Filament\Admin\Concerns\ChecksBranchAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * What has been spent with this supplier, read-only — an expense is created
 * from ExpenseResource, never from here (docs/resource/08-vendor.md §Relations).
 *
 * A vendor can be shared across branches (a fuel station, an insurer), so its
 * expense history can span branches the viewer cannot otherwise reach —
 * `reports.view_financials` (Accountant) does not imply `branches.view_all`.
 */
class ExpensesRelationManager extends RelationManager
{
    use ChecksBranchAccess;

    protected static string $relationship = 'expenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::pinToAccessibleBranches($query)->with('category'))
            ->columns([
                TextColumn::make('reference')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('incurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->defaultSort('incurred_on', 'desc')
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
