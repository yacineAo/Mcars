<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\PaymentScheduleResource\Pages;
use App\Models\PaymentSchedule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PaymentScheduleResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = PaymentSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('customer_id')->relationship('customer', 'first_name')->searchable()->required(),
                TextInput::make('sequence')->numeric()->required(),
                DatePicker::make('due_date')->required(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                Select::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'overdue' => 'Overdue', 'waived' => 'Waived'])->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.first_name')->label('Customer'),
                TextColumn::make('sequence'),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'overdue' => 'Overdue']),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('due_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentSchedules::route('/'),
            'create' => Pages\CreatePaymentSchedule::route('/create'),
            'edit' => Pages\EditPaymentSchedule::route('/{record}/edit'),
        ];
    }
}
