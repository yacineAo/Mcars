<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages;
use App\Models\EmployeeAdvance;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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

class EmployeeAdvanceResource extends Resource
{
    protected static ?string $model = EmployeeAdvance::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('employee_id')->relationship('employee', 'first_name')->searchable()->required(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                DatePicker::make('advanced_on')->required(),
                Textarea::make('reason')->nullable(),
                Select::make('status')->options(['outstanding' => 'Outstanding', 'partially_recovered' => 'Partially Recovered', 'recovered' => 'Recovered', 'written_off' => 'Written Off'])->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')->label('Employee'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('advanced_on')->date()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['outstanding' => 'Outstanding', 'partially_recovered' => 'Partially Recovered', 'recovered' => 'Recovered', 'written_off' => 'Written Off']),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('advanced_on', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeAdvances::route('/'),
            'create' => Pages\CreateEmployeeAdvance::route('/create'),
            'edit' => Pages\EditEmployeeAdvance::route('/{record}/edit'),
        ];
    }
}
