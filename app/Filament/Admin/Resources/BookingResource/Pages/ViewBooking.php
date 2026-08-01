<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\Pages;

use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\CustomerResource;
use App\Models\Booking;
use App\Services\ReportService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The hub screen for a booking.
 *
 * A booking is the record staff reach for when a customer phones, and answering "what
 * was signed, what was charged, what came back damaged, what is still owed" used to
 * mean visiting four other screens. The relation managers carry the first three; the
 * settlement section carries the last.
 */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    /** @var array{invoiced: float, paid: float, outstanding: float}|null */
    private ?array $settlementCache = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Identity'))
                    ->schema([
                        TextEntry::make('reference')->label(__('Reference')),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('branch.name')->label(__('Branch')),
                        IconEntry::make('with_driver')->label(__('With driver'))->boolean(),
                    ])
                    ->columns(4),

                Section::make(__('Customer & Car'))
                    ->schema([
                        TextEntry::make('customer_id')
                            ->label(__('Customer'))
                            ->formatStateUsing(fn (Booking $record): string => $record->customer?->displayName() ?? '—')
                            // Linked only when the viewer may actually open it — a dead
                            // link into a 403 is worse than plain text.
                            ->url(fn (Booking $record): ?string => $record->customer !== null && CustomerResource::canAccess()
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('customer.phone')->label(__('Phone')),
                        TextEntry::make('car.registration_number')->label(__('Car Plate')),
                        TextEntry::make('car_id')
                            ->label(__('Car'))
                            ->formatStateUsing(fn (Booking $record): string => $record->car === null
                                ? '—'
                                : trim($record->car->brand.' '.$record->car->model)),
                    ])
                    ->columns(4),

                Section::make(__('Dates'))
                    // Expected beside actual: "did this car go out and come back when it
                    // was meant to" is the question, and it cannot be answered by either
                    // column alone.
                    ->schema([
                        TextEntry::make('pickup_at')->label(__('Expected pickup'))->dateTime(),
                        TextEntry::make('actual_pickup_at')->label(__('Actual pickup'))->dateTime()->placeholder('—'),
                        TextEntry::make('expected_return_at')
                            ->label(__('Expected return'))
                            ->dateTime()
                            ->color(fn (Booking $record): ?string => $record->isOverdue() ? 'danger' : null),
                        TextEntry::make('actual_return_at')->label(__('Actual return'))->dateTime()->placeholder('—'),
                        TextEntry::make('days_count')->label(__('Days count')),
                        TextEntry::make('odometer_out')->label(__('Odometer out'))->placeholder('—'),
                        TextEntry::make('odometer_in')->label(__('Odometer in'))->placeholder('—'),
                    ])
                    ->columns(4),

                Section::make(__('Pricing'))
                    ->schema([
                        TextEntry::make('daily_rate')->label(__('Daily rate'))->money('DZD'),
                        TextEntry::make('subtotal')->label(__('Subtotal'))->money('DZD'),
                        TextEntry::make('extras_total')->label(__('Extras'))->money('DZD'),
                        TextEntry::make('discount_amount')->label(__('Discount'))->money('DZD'),
                        TextEntry::make('total_amount')->label(__('Total'))->money('DZD')->weight('bold'),
                        TextEntry::make('security_deposit_amount')->label(__('Security deposit'))->money('DZD'),
                    ])
                    ->columns(3),

                Section::make(__('Settlement'))
                    ->description(__('Derived from the ledger — never a stored balance.'))
                    // Revenue and receivables are money in the reporting sense, unlike
                    // the contracted total above, which a receptionist needs in order to
                    // take payment at all.
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('invoiced')
                            ->label(__('Invoiced'))
                            ->state(fn (Booking $record): float => $this->settlement($record)['invoiced'])
                            ->money('DZD'),
                        TextEntry::make('paid')
                            ->label(__('Paid to date'))
                            ->state(fn (Booking $record): float => $this->settlement($record)['paid'])
                            ->money('DZD'),
                        TextEntry::make('outstanding')
                            ->label(__('Outstanding'))
                            ->state(fn (Booking $record): float => $this->settlement($record)['outstanding'])
                            ->money('DZD')
                            ->weight('bold')
                            ->color(fn (Booking $record): string => $this->settlement($record)['outstanding'] > 0
                                ? 'danger'
                                : 'success'),
                    ])
                    ->columns(3),

                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('cancellation_reason')
                            ->label(__('Cancellation reason'))
                            ->placeholder('—')
                            ->visible(fn (Booking $record): bool => filled($record->cancellation_reason)),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * One ledger aggregation per page render, not one per entry.
     *
     * Three entries read these figures; without memoising, opening a booking would run
     * the same aggregate three times.
     *
     * @return array{invoiced: float, paid: float, outstanding: float}
     */
    private function settlement(Booking $record): array
    {
        return $this->settlementCache ??= app(ReportService::class)->bookingSettlement($record->id);
    }
}
