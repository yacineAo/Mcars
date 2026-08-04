<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\RelationManagers;

use App\Filament\Admin\Resources\PaymentResource;
use App\Models\PaymentScheduleAllocation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Which payments settled this instalment, and for how much — read-only. There
 * is no stored `amount_paid` (docs/resource/24-payment-schedule.md): the
 * figure is derived from this join, and a payment is recorded on the booking
 * or contract, never from here.
 */
class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentAllocations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('payment'))
            ->columns([
                TextColumn::make('payment.reference')
                    ->label(__('Payment'))
                    ->url(fn (PaymentScheduleAllocation $record): ?string => $record->payment !== null && PaymentResource::canAccess()
                        ? PaymentResource::getUrl('view', ['record' => $record->payment])
                        : null),
                TextColumn::make('payment.paid_at')
                    ->label(__('Paid at'))
                    ->date(),
                TextColumn::make('amount')
                    ->money('DZD'),
            ])
            ->defaultSort('id')
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
