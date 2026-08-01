<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DepositResource\Pages;

use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\DepositResource\RelationManagers\DeductionsRelationManager;
use App\Filament\Admin\Resources\DepositResource\RelationManagers\TransactionsRelationManager;
use App\Models\Deposit;
use App\Services\Payment\DepositService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A deposit's meaning is its history — taken, drawn down twice, partially
 * refunded — so the view page leads with the balance and the story below it.
 */
class ViewDeposit extends ViewRecord
{
    protected static string $resource = DepositResource::class;

    public function infolist(Schema $schema): Schema
    {
        $deposits = app(DepositService::class);

        return $schema
            ->schema([
                Section::make(__('Money'))
                    ->schema([
                        TextEntry::make('amount')
                            ->label(__('deposits.fields.amount_held'))
                            ->money('DZD')
                            ->weight('bold')
                            ->helperText(__('deposits.fields.amount_held_help')),
                        TextEntry::make('remaining_balance')
                            ->label(__('deposits.fields.remaining_balance'))
                            ->state(fn (Deposit $record): string => $deposits->remainingBalance($record)->toDecimal())
                            ->money('DZD'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('method'),
                    ])
                    ->columns(4),

                Section::make(__('Booking & Customer'))
                    ->schema([
                        TextEntry::make('booking.reference')
                            ->label(__('Booking'))
                            // Linked only when the viewer may actually open it — a dead
                            // link into a 403 is worse than plain text.
                            ->url(fn (Deposit $record): ?string => $record->booking !== null && BookingResource::canAccess()
                                ? BookingResource::getUrl('view', ['record' => $record->booking])
                                : null),
                        TextEntry::make('customer_id')
                            ->label(__('Customer'))
                            ->formatStateUsing(fn (Deposit $record): string => $record->customer?->displayName() ?? '—')
                            ->url(fn (Deposit $record): ?string => $record->customer !== null && CustomerResource::canAccess()
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('branch.name')->label(__('Branch'))->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('Timeline'))
                    ->schema([
                        TextEntry::make('held_at')->label(__('Held at'))->dateTime(),
                        TextEntry::make('settled_at')->label(__('Settled at'))->dateTime()->placeholder('—'),
                        TextEntry::make('settledBy.name')->label(__('Settled by'))->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * The deposit's history: what was drawn down, and the ledger rows behind
     * it. Both strictly read-only — the rows are created by the hold, deduct
     * and refund actions through DepositService, which owns the cap.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('History'), [
                DeductionsRelationManager::class,
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
