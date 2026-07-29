<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BookingStatus;
use App\Enums\FuelLevel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Booking\BookingService;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class BookingResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    Step::make('Customer & Car')
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('customer_id')
                                    ->label('Customer')
                                    ->options(fn () => Customer::query()
                                        ->orderBy('first_name')
                                        ->get()
                                        ->mapWithKeys(fn (Customer $c) => [$c->id => $c->first_name
                                            ? "{$c->first_name} {$c->last_name} — {$c->phone}"
                                            : ($c->company_name ?? $c->phone),
                                        ])
                                        ->toArray())
                                    ->searchable()
                                    ->required(),
                                Select::make('car_id')
                                    ->relationship('car', 'registration_number')
                                    ->searchable()
                                    ->required(),
                            ]),
                        ]),
                    Step::make('Dates')
                        ->schema([
                            Grid::make(2)->schema([
                                DateTimePicker::make('pickup_at')->required(),
                                DateTimePicker::make('expected_return_at')->required(),
                                Select::make('pickup_branch_id')
                                    ->relationship('pickupBranch', 'name')
                                    ->nullable(),
                                Select::make('return_branch_id')
                                    ->relationship('returnBranch', 'name')
                                    ->nullable(),
                            ]),
                        ]),
                    Step::make('Pricing')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('daily_rate')->numeric()->required()->prefix('DZD'),
                                TextInput::make('days_count')->numeric()->required(),
                                TextInput::make('subtotal')->numeric()->required()->prefix('DZD'),
                                TextInput::make('extras_total')->numeric()->default(0)->prefix('DZD'),
                                TextInput::make('discount_amount')->numeric()->default(0)->prefix('DZD'),
                                TextInput::make('total_amount')->numeric()->required()->prefix('DZD'),
                                TextInput::make('security_deposit_amount')->numeric()->default(0)->prefix('DZD'),
                            ]),
                        ]),
                    Step::make('Options')
                        ->schema([
                            Grid::make(2)->schema([
                                Toggle::make('with_driver'),
                                Select::make('driver_employee_id')
                                    ->relationship('driverEmployee', 'name')
                                    ->nullable(),
                                Select::make('sales_agent_id')
                                    ->relationship('salesAgent', 'name')
                                    ->nullable(),
                            ]),
                            Section::make('Additional Drivers')
                                ->schema([
                                    Repeater::make('additionalDrivers')
                                        ->relationship()
                                        ->schema([
                                            TextInput::make('full_name')->required(),
                                            TextInput::make('national_id'),
                                            TextInput::make('driving_license_number'),
                                            TextInput::make('phone'),
                                        ])
                                        ->defaultItems(0),
                                ]),
                        ]),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference'),
                TextColumn::make('car.registration_number'),
                TextColumn::make('customer.first_name')->label('Customer'),
                TextColumn::make('pickup_at')->dateTime(),
                TextColumn::make('expected_return_at')->dateTime(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('total_amount')->money('DZD'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(BookingStatus::options()),
            ])
            // Every lifecycle step goes through BookingService. These used to be
            // bare status updates, which meant a car could be handed over without
            // the rental ever reaching the ledger — no revenue, no receivable, and
            // a dashboard that under-reported the day's takings.
            ->actions([
                Action::make('confirm')
                    ->label(__('bookings.actions.confirm'))
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Booking $record, BookingService $bookings): void {
                        $bookings->confirm($record, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.confirmed'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => $record->status->is(
                        BookingStatus::Draft,
                        BookingStatus::Pending,
                    )),

                Action::make('checkout')
                    ->label(__('bookings.actions.checkout'))
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->modalHeading(__('bookings.actions.checkout_heading'))
                    ->modalDescription(__('bookings.actions.checkout_description'))
                    ->form([
                        DateTimePicker::make('actual_pickup_at')
                            ->label(__('bookings.fields.actual_pickup_at'))
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_out')
                            ->label(__('bookings.fields.odometer_out'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('fuel_level_out')
                            ->label(__('bookings.fields.fuel_level_out'))
                            ->options(FuelLevel::options())
                            ->required(),
                    ])
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->checkOut($record, $data, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.checked_out'))
                            ->body(__('bookings.notifications.revenue_posted'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed),

                Action::make('checkin')
                    ->label(__('bookings.actions.checkin'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading(__('bookings.actions.checkin_heading'))
                    ->modalDescription(__('bookings.actions.checkin_description'))
                    ->form([
                        DateTimePicker::make('actual_return_at')
                            ->label(__('bookings.fields.actual_return_at'))
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_in')
                            ->label(__('bookings.fields.odometer_in'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('fuel_level_in')
                            ->label(__('bookings.fields.fuel_level_in'))
                            ->options(FuelLevel::options())
                            ->required(),
                    ])
                    // checkInWithCharges also posts the closeout extras (late hours,
                    // excess km, fuel shortfall) when a check-in condition report
                    // exists; without one it closes the rental with no extra charges.
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->checkInWithCharges($record, $data, Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.checked_in'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => $record->status->is(
                        BookingStatus::Active,
                        BookingStatus::Overdue,
                    )),

                Action::make('cancel')
                    ->label(__('bookings.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('reason')
                            ->label(__('bookings.fields.cancellation_reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data, BookingService $bookings): void {
                        $bookings->cancel($record, $data['reason'], Auth::user());

                        Notification::make()
                            ->success()
                            ->title(__('bookings.notifications.cancelled'))
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => ! $record->status->isTerminal()),

                Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('DZD')
                            ->required(),
                        Select::make('method')
                            ->options(PaymentMethod::options())
                            ->required(),
                        Select::make('financial_account_id')
                            ->relationship('financialAccount', 'name')
                            ->searchable()
                            ->nullable(),
                        Textarea::make('notes'),
                    ])
                    ->action(function (Booking $record, array $data, PaymentService $payments): void {
                        $payment = Payment::create([
                            'branch_id' => $record->branch_id,
                            'direction' => PaymentDirection::Inbound,
                            'payable_type' => Booking::class,
                            'payable_id' => $record->id,
                            'customer_id' => $record->customer_id,
                            'method' => $data['method'],
                            'amount' => $data['amount'],
                            'currency' => 'DZD',
                            'paid_at' => now(),
                            'financial_account_id' => $data['financial_account_id'] ?? null,
                            'received_by_id' => Auth::id(),
                            'notes' => $data['notes'] ?? null,
                        ]);

                        $payments->recordPayment($payment, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title('Payment recorded successfully')
                            ->send();
                    })
                    ->visible(fn (Booking $record): bool => ! $record->status->is(BookingStatus::Cancelled, BookingStatus::Draft)),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
