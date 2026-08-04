<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\RelationManagers;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * What has been done for this garage, read-only — a log is created from a car
 * or a schedule, never from here (docs/resource/08-vendor.md §Relations).
 *
 * A vendor can be shared across branches, so its service history can span
 * branches the viewer cannot otherwise reach — `fleet.view` does not imply
 * `branches.view_all`.
 */
class MaintenanceLogsRelationManager extends RelationManager
{
    use ChecksBranchAccess;

    protected static string $relationship = 'maintenanceLogs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::pinToAccessibleBranches($query)->with('car'))
            ->columns([
                TextColumn::make('car.registration_number')
                    ->label('Car')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (MaintenanceType::tryFrom($state)?->getLabel() ?? $state)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (MaintenanceStatus::tryFrom($state)?->getLabel() ?? $state)),
                TextColumn::make('scheduled_for')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('completed_at')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('total_cost')
                    ->money('DZD')
                    ->sortable()
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
            ])
            ->defaultSort('scheduled_for', 'desc')
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
