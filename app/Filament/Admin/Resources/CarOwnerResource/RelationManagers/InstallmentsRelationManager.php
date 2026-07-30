<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\RelationManagers;

use App\Enums\InstallmentStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ownerInstallments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Instalments');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
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
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('sequence_number')
                    ->label('#')
                    ->formatStateUsing(fn (?int $state, $record): string => $state === null
                        ? '—'
                        : $state.'/'.($record->total_installments ?? '?')),
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
