<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CashSessionResource\Pages\CreateCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\EditCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\ListCashSessions;
use App\Models\CashSession;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('opening_float')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->default(0),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Account'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('opening_float')
                    ->money('DZD'),
                TextColumn::make('counted_amount')
                    ->money('DZD'),
                TextColumn::make('openedBy.name')
                    ->label('Opened By'),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'create' => CreateCashSession::route('/create'),
            'edit' => EditCashSession::route('/{record}/edit'),
        ];
    }
}
