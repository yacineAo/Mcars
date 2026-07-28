<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\FinancialAccountType;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\CreateFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\EditFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\ListFinancialAccounts;
use App\Models\FinancialAccount;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class FinancialAccountResource extends Resource
{
    protected static ?string $model = FinancialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('ledger_account_id')
                    ->relationship('ledgerAccount', 'name')
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(FinancialAccountType::options())
                    ->required(),
                Select::make('payment_method')
                    ->options(PaymentMethod::options())
                    ->multiple()
                    ->label('Allowed Payment Methods'),
                TextInput::make('account_number')
                    ->maxLength(50),
                TextInput::make('rib')
                    ->maxLength(50),
                TextInput::make('holder_name')
                    ->maxLength(255),
                TextInput::make('opening_balance')
                    ->numeric()
                    ->prefix('DZD')
                    ->default(0),
                DatePicker::make('opened_on')
                    ->required(),
                Toggle::make('is_default_for_cash'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('ledgerAccount.name')
                    ->label('Ledger Account'),
                TextColumn::make('branch.name')
                    ->label('Branch'),
                IconColumn::make('is_default_for_cash')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialAccounts::route('/'),
            'create' => CreateFinancialAccount::route('/create'),
            'edit' => EditFinancialAccount::route('/{record}/edit'),
        ];
    }
}
