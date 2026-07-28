<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Models\ContractTemplate;
use BackedEnum;
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

class ContractTemplateResource extends Resource
{
    protected static ?string $model = ContractTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')->required(),
                Select::make('locale')
                    ->options(['ar' => 'Arabic', 'fr' => 'French', 'en' => 'English'])
                    ->required(),
                Textarea::make('body')->required()->rows(20)->columnSpanFull(),
                TextInput::make('terms_version')->default('1.0'),
                Toggle::make('is_active')->default(true),
                Toggle::make('is_default')->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('locale'),
                TextColumn::make('terms_version'),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('is_default')->boolean(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ContractTemplateResource\Pages\ListContractTemplates::route('/'),
            'create' => ContractTemplateResource\Pages\CreateContractTemplate::route('/create'),
            'edit' => ContractTemplateResource\Pages\EditContractTemplate::route('/{record}/edit'),
        ];
    }
}
