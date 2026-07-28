<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DepositStatus;
use App\Filament\Admin\Resources\DepositResource\Pages;
use App\Models\Deposit;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')->relationship('booking', 'reference')->searchable()->required(),
                Select::make('customer_id')->relationship('customer', 'first_name')->searchable()->required(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                Select::make('method')->options(['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'baridimob' => 'BaridiMob', 'card' => 'Card'])->required(),
                Select::make('status')->options(DepositStatus::options())->required(),
                DateTimePicker::make('held_at')->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference'),
                TextColumn::make('customer.first_name')->label('Customer'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('method'),
                TextColumn::make('status')->badge(),
                TextColumn::make('held_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DepositStatus::options()),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('held_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeposits::route('/'),
            'create' => Pages\CreateDeposit::route('/create'),
            'edit' => Pages\EditDeposit::route('/{record}/edit'),
        ];
    }
}
