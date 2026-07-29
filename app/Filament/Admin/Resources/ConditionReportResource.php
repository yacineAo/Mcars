<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\ConditionReport;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
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

class ConditionReportResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ConditionReport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')
                    ->relationship('booking', 'reference')
                    ->searchable()
                    ->required(),
                Select::make('type')
                    ->options(ConditionReportType::options())
                    ->required(),
                DateTimePicker::make('performed_at')->required(),
                TextInput::make('odometer')->numeric()->nullable(),
                Select::make('fuel_level')
                    ->options(FuelLevel::options())
                    ->nullable(),
                Toggle::make('is_clean')->default(true),
                Textarea::make('notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference'),
                TextColumn::make('type'),
                TextColumn::make('performed_at')->dateTime(),
                TextColumn::make('performedBy.name'),
                IconColumn::make('is_clean')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('performed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ConditionReportResource\Pages\ListConditionReports::route('/'),
            'create' => ConditionReportResource\Pages\CreateConditionReport::route('/create'),
            'edit' => ConditionReportResource\Pages\EditConditionReport::route('/{record}/edit'),
        ];
    }
}
