<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('expense_category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function (callable $set, $state): void {
                        if ($state) {
                            $category = ExpenseCategory::find($state);
                            if ($category && $category->is_car_related) {
                                $set('car_id_required', true);
                            }
                        }
                    }),
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->nullable(),
                Select::make('vendor_id')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->nullable(),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->reactive()
                    // No tax is charged, so the total simply mirrors the amount.
                    // It stays a stored column because an expense may later be
                    // split or adjusted independently of the entered amount.
                    ->afterStateUpdated(function (callable $set, $state): void {
                        $set('total_amount', number_format((float) $state, 2, '.', ''));
                    }),
                TextInput::make('total_amount')
                    ->numeric()
                    ->prefix('DZD')
                    ->required(),
                DatePicker::make('incurred_on')
                    ->required(),
                TextInput::make('invoice_number')
                    ->maxLength(100),
                Textarea::make('description')
                    ->maxLength(65535),
                Select::make('status')
                    ->options(ExpenseStatus::options())
                    ->required()
                    ->default('draft'),
                // Payment fields (shown when status = paid)
                Select::make('payment_method')
                    ->options(PaymentMethod::options())
                    ->nullable(),
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->nullable(),
                Toggle::make('is_recurring'),
                Textarea::make('recurrence_rule')
                    ->maxLength(65535),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('incurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->label('Vendor'),
                TextColumn::make('car.registration_number')
                    ->label('Car'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([])
            ->actions([
                ViewAction::make(),
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
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
