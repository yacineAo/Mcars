<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Filament\Admin\Resources\FineResource\Pages;
use App\Models\Fine;
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

class FineResource extends Resource
{
    protected static ?string $model = Fine::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('reference')->required()->maxLength(32),
                Select::make('car_id')->relationship('car', 'registration_number')->searchable()->required(),
                Select::make('customer_id')->relationship('customer', 'first_name')->searchable()->nullable(),
                Select::make('type')->options(FineType::options())->required(),
                TextInput::make('notice_number')->nullable(),
                TextInput::make('authority')->nullable(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                TextInput::make('total_amount')->numeric()->required()->prefix('DZD'),
                DateTimePicker::make('violation_at')->required(),
                DateTimePicker::make('received_at')->required(),
                Select::make('liability')->options(FineLiability::options())->required(),
                Select::make('status')->options(FineStatus::options())->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('car.registration_number'),
                TextColumn::make('type'),
                TextColumn::make('total_amount')->money('DZD')->sortable(),
                TextColumn::make('liability')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('violation_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('liability')->options(FineLiability::options()),
                SelectFilter::make('status')->options(FineStatus::options()),
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
            ->defaultSort('violation_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFines::route('/'),
            'create' => Pages\CreateFine::route('/create'),
            'edit' => Pages\EditFine::route('/{record}/edit'),
        ];
    }
}
