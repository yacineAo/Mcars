<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Money taken against this booking, read-only.
 *
 * Recording a payment is the `record_payment` action, which goes through
 * PaymentService so the row and its ledger posting are made together. Creating one
 * here would produce a `payments` row that no balance in the system can see.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->searchable(),
                TextColumn::make('paid_at')->label(__('Date'))->date()->sortable(),
                TextColumn::make('method')->label(__('Method'))->badge(),
                TextColumn::make('amount')->label(__('Amount'))->money('DZD'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('receivedBy.name')->label(__('Received by'))->placeholder('—'),
            ])
            ->defaultSort('paid_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('receivedBy'))
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
