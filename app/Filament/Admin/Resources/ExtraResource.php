<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ExtraPricingUnit;
use App\Models\Extra;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ExtraResource extends Resource
{
    protected static ?string $model = Extra::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->unique(ignoreRecord: true),
                Select::make('pricing_unit')
                    ->options(ExtraPricingUnit::options())
                    ->required(),
                TextInput::make('unit_price')->numeric()->required()->prefix('DZD'),
                Select::make('ledger_account_id')
                    ->relationship('ledgerAccount', 'name')
                    ->searchable()
                    ->required(),
                Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('pricing_unit'),
                TextColumn::make('unit_price')->money('DZD'),
                IconColumn::make('is_active')->boolean(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ExtraResource\Pages\ListExtras::route('/'),
            'create' => ExtraResource\Pages\CreateExtra::route('/create'),
            'edit' => ExtraResource\Pages\EditExtra::route('/{record}/edit'),
        ];
    }
}
