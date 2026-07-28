<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranches;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
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

/**
 * @property Schema $form
 */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->required()
                    ->maxLength(10)
                    ->unique(ignoreRecord: true),
                TextInput::make('address')
                    ->maxLength(255),
                TextInput::make('city')
                    ->maxLength(255),
                Select::make('wilaya')
                    ->searchable()
                    ->options([
                        'Alger' => 'Alger',
                        'Oran' => 'Oran',
                        'Constantine' => 'Constantine',
                    ]),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Select::make('manager_user_id')
                    ->relationship('manager', 'name')
                    ->searchable()
                    ->preload(),
                Toggle::make('is_active'),
                Toggle::make('is_default'),
                Textarea::make('notes')
                    ->maxLength(65535),
                Placeholder::make('created_at')
                    ->content(fn (Branch $record): ?string => $record->created_at?->format('Y-m-d H:i:s'))
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('is_default')
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
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
