<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\Pages;

use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\ContractResource;
use App\Filament\Admin\Resources\PaymentScheduleResource;
use App\Filament\Admin\Resources\PaymentScheduleResource\RelationManagers\AllocationsRelationManager;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\PaymentSchedule;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewPaymentSchedule extends ViewRecord
{
    protected static string $resource = PaymentScheduleResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Instalment'))
                    ->schema([
                        TextEntry::make('customer.first_name')
                            ->label(__('payment_schedules.fields.customer'))
                            ->formatStateUsing(fn (PaymentSchedule $record): string => $record->customer?->displayName() ?? '—'),
                        TextEntry::make('sequence')
                            ->label(__('payment_schedules.fields.sequence')),
                        TextEntry::make('due_date')
                            ->label(__('payment_schedules.fields.due_date'))
                            ->date(),
                        TextEntry::make('amount')
                            ->label(__('payment_schedules.fields.amount'))
                            ->money('DZD')
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->label(__('payment_schedules.fields.status'))
                            ->badge(),
                        TextEntry::make('schedulable_id')
                            ->label(__('payment_schedules.groups.plan'))
                            ->formatStateUsing(fn (PaymentSchedule $record): string => match ($record->schedulable_type) {
                                Booking::class => __('payment_schedules.fields.booking'),
                                Contract::class => __('payment_schedules.fields.contract'),
                                default => '—',
                            })
                            ->url(fn (PaymentSchedule $record): ?string => match (true) {
                                $record->schedulable_type === Booking::class && $record->schedulable !== null && BookingResource::canAccess() => BookingResource::getUrl('view', ['record' => $record->schedulable]),
                                $record->schedulable_type === Contract::class && $record->schedulable !== null && ContractResource::canAccess() => ContractResource::getUrl('view', ['record' => $record->schedulable]),
                                default => null,
                            }),
                        TextEntry::make('reminder_sent_at')
                            ->label(__('payment_schedules.fields.reminder_sent'))
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3),
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
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Payments'), [
                AllocationsRelationManager::class,
            ]),
        ];
    }
}
