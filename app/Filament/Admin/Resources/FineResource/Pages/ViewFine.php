<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\Pages;

use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\ContractResource;
use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\FineResource;
use App\Filament\Admin\Resources\FineResource\RelationManagers\TransactionsRelationManager;
use App\Models\Fine;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The full record a disputed fine is read from: the notice, the liability
 * decision with who made it and why, the related booking/customer/car, and the
 * ledger posting behind it all.
 */
class ViewFine extends ViewRecord
{
    protected static string $resource = FineResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('fines.sections.notice'))
                    ->schema([
                        TextEntry::make('reference')->label(__('fines.fields.reference')),
                        TextEntry::make('notice_number')
                            ->label(__('fines.fields.notice_number'))
                            ->placeholder('—'),
                        TextEntry::make('authority')->label(__('fines.fields.authority'))->placeholder('—'),
                        TextEntry::make('type')->label(__('fines.fields.type'))->badge(),
                        TextEntry::make('violation_at')
                            ->label(__('fines.fields.violation_at'))
                            ->dateTime(),
                        TextEntry::make('location')->label(__('fines.fields.location'))->placeholder('—'),
                        TextEntry::make('received_at')
                            ->label(__('fines.fields.received_at'))
                            ->dateTime(),
                        TextEntry::make('due_date')->label(__('fines.fields.due_date'))->date()->placeholder('—'),
                    ])
                    ->columns(4),

                Section::make(__('fines.sections.money'))
                    ->schema([
                        TextEntry::make('amount')
                            ->label(__('fines.fields.amount'))
                            ->money('DZD'),
                        TextEntry::make('late_penalty_amount')
                            ->label(__('fines.fields.late_penalty_amount'))
                            ->money('DZD'),
                        TextEntry::make('total_amount')
                            ->label(__('fines.fields.total_amount'))
                            ->money('DZD')
                            ->weight('bold'),
                    ])
                    ->columns(3),

                Section::make(__('fines.sections.liability'))
                    ->schema([
                        TextEntry::make('liability')->label(__('fines.fields.liability'))->badge(),
                        TextEntry::make('status')->label(__('fines.fields.status'))->badge(),
                        TextEntry::make('liability_determined_at')
                            ->label(__('fines.fields.determined_at'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('liabilityDeterminedBy.name')
                            ->label(__('fines.fields.determined_by'))
                            ->placeholder('—'),
                        TextEntry::make('liability_note')
                            ->label(__('fines.fields.liability_note'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(4),

                Section::make(__('fines.sections.related'))
                    ->schema([
                        TextEntry::make('customer_id')
                            ->label(__('fines.fields.customer'))
                            ->formatStateUsing(fn (Fine $record): string => $record->customer?->displayName() ?? '—')
                            ->url(fn (Fine $record): ?string => $record->customer !== null && CustomerResource::canAccess()
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('booking_id')
                            ->label(__('fines.fields.booking'))
                            ->formatStateUsing(fn (Fine $record): string => $record->booking->reference ?? '—')
                            ->url(fn (Fine $record): ?string => $record->booking !== null && BookingResource::canAccess()
                                ? BookingResource::getUrl('view', ['record' => $record->booking])
                                : null),
                        TextEntry::make('contract_id')
                            ->label(__('fines.fields.contract'))
                            ->formatStateUsing(fn (Fine $record): string => $record->contract->contract_number ?? '—')
                            ->url(fn (Fine $record): ?string => $record->contract !== null && ContractResource::canAccess()
                                ? ContractResource::getUrl('view', ['record' => $record->contract])
                                : null),
                        TextEntry::make('car_id')
                            ->label(__('fines.fields.car'))
                            ->formatStateUsing(fn (Fine $record): string => $record->car->registration_number ?? '—')
                            ->url(fn (Fine $record): ?string => $record->car !== null && CarResource::canAccess()
                                ? CarResource::getUrl('view', ['record' => $record->car])
                                : null),
                    ])
                    ->columns(2),

                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * The ledger posting behind the decision, strictly read-only and gated on
     * reports.view_financials.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('fines.sections.history'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
