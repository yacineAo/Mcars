<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\InstallmentStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Owner rent owed against this car (E32). Money, so it is gated on
 * `reports.view_financials` — the permission, never a role list. Read-only: an instalment is
 * accrued and paid through the owner-payment path, where the ledger postings live.
 *
 * `amount_due` is the accrued figure on the row. What is still *outstanding* is the balance
 * of 2200 filtered by owner and is never stored — see docs/05-accounting-model.md.
 */
class OwnerInstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ownerInstallments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Owner Instalments');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
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
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('sequence_number')
                    ->label('#')
                    ->formatStateUsing(fn (?int $state, $record): string => $state === null
                        ? '—'
                        // A null total means the agreement is open-ended — there
                        // is no count to show, only the sequence number.
                        : ($record->total_installments !== null
                            ? $state.'/'.$record->total_installments
                            : (string) $state)),
                TextColumn::make('amount_due')
                    ->label('Amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InstallmentStatus::options()),
            ])
            ->defaultSort('due_date', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
