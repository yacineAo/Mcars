<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\PaymentResource\Pages;
use App\Models\Payment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('reference')->required()->maxLength(32),
                Select::make('direction')->options(['inbound' => 'Inbound', 'outbound' => 'Outbound'])->required(),
                Select::make('method')->options(PaymentMethod::options())->required(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                DatePicker::make('paid_at')->required(),
                Select::make('status')->options(['completed' => 'Completed', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded', 'bounced' => 'Bounced'])->required(),
                Select::make('customer_id')->relationship('customer', 'first_name')->searchable()->nullable(),
                Select::make('financial_account_id')->relationship('financialAccount', 'name')->nullable(),
                TextInput::make('external_reference')->nullable(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('direction')->badge(),
                TextColumn::make('method'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('paid_at')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('customer.first_name')->label('Customer'),
            ])
            ->filters([
                SelectFilter::make('direction')->options(['inbound' => 'Inbound', 'outbound' => 'Outbound']),
                SelectFilter::make('status')->options(['completed' => 'Completed', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded']),
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
            ->defaultSort('paid_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
