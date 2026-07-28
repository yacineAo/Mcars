<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\CreateChartOfAccount;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\EditChartOfAccount;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\ListChartOfAccounts;
use App\Models\ChartOfAccount;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_ar')
                    ->maxLength(255),
                TextInput::make('name_fr')
                    ->maxLength(255),
                Select::make('type')
                    ->options(AccountType::options())
                    ->required(),
                Select::make('normal_balance')
                    ->options(NormalBalance::options())
                    ->required(),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->nullable(),
                Toggle::make('is_cash_equivalent'),
                Toggle::make('is_postable')
                    ->default(true),
                Toggle::make('is_system'),
                Toggle::make('is_active')
                    ->default(true),
                Textarea::make('description')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('normal_balance'),
                IconColumn::make('is_cash_equivalent')
                    ->boolean(),
                IconColumn::make('is_postable')
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
            'index' => ListChartOfAccounts::route('/'),
            'create' => CreateChartOfAccount::route('/create'),
            'edit' => EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
