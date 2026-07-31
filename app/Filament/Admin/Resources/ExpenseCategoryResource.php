<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ExpenseCategoryResource\Pages\CreateExpenseCategory;
use App\Filament\Admin\Resources\ExpenseCategoryResource\Pages\EditExpenseCategory;
use App\Filament\Admin\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ExpenseCategoryResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof ExpenseCategory)) {
            return false;
        }

        if ($record->hasExpenses()) {
            return false;
        }

        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, string $state, callable $set) => $operation === 'create' ? $set('slug', ExpenseCategory::slugFromName($state)) : null),
                TextInput::make('name_ar')
                    ->maxLength(255),
                TextInput::make('name_fr')
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->helperText(__('Auto-derived from name.')),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->nullable(),
                Select::make('ledger_account_id')
                    ->relationship('ledgerAccount', 'name', fn (Builder $query): Builder => $query->where('is_postable', true)->where('type', AccountType::Expense))
                    ->searchable()
                    ->required()
                    ->disabled(fn (?ExpenseCategory $record): bool => $record !== null && $record->exists && $record->hasExpenses())
                    ->helperText(fn (?ExpenseCategory $record) => $record !== null && $record->exists && $record->hasExpenses() ? __('Ledger account cannot be changed once expenses exist in this category.') : null),
                Toggle::make('is_car_related'),
                Toggle::make('is_recurring_default'),
                Toggle::make('is_active')
                    ->default(true),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->description(fn (ExpenseCategory $record) => $record->parent instanceof ExpenseCategory ? __('Parent: :name', ['name' => $record->parent->name]) : null),
                TextColumn::make('slug')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ledgerAccount.name')
                    ->label('Ledger Account'),
                IconColumn::make('is_car_related')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->default(true),
                TernaryFilter::make('is_car_related'),
                SelectFilter::make('ledger_account_id')
                    ->label('Ledger Account')
                    ->relationship('ledgerAccount', 'name'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('parent')->orderBy('sort_order')->orderBy('name'))
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
