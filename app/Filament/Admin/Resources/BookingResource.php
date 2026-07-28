<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BookingStatus;
use App\Filament\Admin\Resources\BookingResource\Pages;
use App\Models\Booking;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BookingResource extends Resource
{
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
                                    ->relationship('customer', 'first_name')
                                    ->getOptionLabelFromRecordUsing(fn ($r) => "{$r->first_name} {$r->last_name} — {$r->phone}")
                                    ->searchable(['first_name', 'last_name', 'phone', 'national_id'])
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
                    ->badge()
                    ->color(fn (BookingStatus $s): string => $s->getColor()),
                TextColumn::make('total_amount')->money('DZD'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(BookingStatus::options()),
            ])
            ->actions([
                Action::make('checkout')
                    ->action(fn (Booking $record) => $record->update(['status' => BookingStatus::Active]))
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed),
                Action::make('complete')
                    ->action(fn (Booking $record) => $record->update(['status' => BookingStatus::Completed]))
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Active),
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
