<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CommissionResource\Pages;
use App\Models\Commission;
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
use Filament\Tables\Table;
use UnitEnum;

class CommissionResource extends Resource
{
    protected static ?string $model = Commission::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('employee_id')->relationship('employee', 'first_name')->searchable()->required(),
                Select::make('booking_id')->relationship('booking', 'reference')->searchable()->nullable(),
                TextInput::make('basis_amount')->numeric()->required()->prefix('DZD'),
                TextInput::make('rate')->numeric()->required()->suffix('%'),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                DatePicker::make('earned_on')->required(),
                Select::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'cancelled' => 'Cancelled'])->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')->label('Employee'),
                TextColumn::make('booking.reference'),
                TextColumn::make('basis_amount')->money('DZD'),
                TextColumn::make('rate')->suffix('%'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('earned_on')->date(),
            ])
            ->filters([])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('earned_on', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
            'create' => Pages\CreateCommission::route('/create'),
            'edit' => Pages\EditCommission::route('/{record}/edit'),
        ];
    }
}
