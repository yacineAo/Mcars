<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BlockReason;
use App\Models\CarBlock;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CarBlockResource extends Resource
{
    protected static ?string $model = CarBlock::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->required(),
                Select::make('reason')
                    ->options(BlockReason::options())
                    ->required(),
                DateTimePicker::make('starts_at')->required(),
                DateTimePicker::make('ends_at')->required(),
                Select::make('maintenance_log_id')
                    ->relationship('maintenanceLog', 'id')
                    ->nullable(),
                Textarea::make('notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number'),
                TextColumn::make('reason'),
                TextColumn::make('starts_at')->dateTime(),
                TextColumn::make('ends_at')->dateTime(),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => CarBlockResource\Pages\ListCarBlocks::route('/'),
            'create' => CarBlockResource\Pages\CreateCarBlock::route('/create'),
            'edit' => CarBlockResource\Pages\EditCarBlock::route('/{record}/edit'),
        ];
    }
}
