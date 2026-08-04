<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\CreateChartOfAccount;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\EditChartOfAccount;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\ListChartOfAccounts;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\ViewChartOfAccount;
use App\Models\ChartOfAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ChartOfAccountResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ChartOfAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof ChartOfAccount)) {
            return false;
        }

        if ($record->is_system) {
            return false;
        }

        if ($record->hasPostings()) {
            return false;
        }

        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?ChartOfAccount $record): bool => $record !== null && $record->exists && $record->hasPostings())
                    ->helperText(fn (?ChartOfAccount $record): ?string => $record !== null && $record->exists && $record->hasPostings() ? __('Code cannot be changed once the account has postings.') : null),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_ar')
                    ->maxLength(255),
                TextInput::make('name_fr')
                    ->maxLength(255),
                Select::make('type')
                    ->options(AccountType::options())
                    ->required()
                    ->disabled(fn (?ChartOfAccount $record): bool => $record !== null && $record->exists && $record->hasPostings())
                    ->helperText(fn (?ChartOfAccount $record): ?string => $record !== null && $record->exists && $record->hasPostings() ? __('Type cannot be changed once the account has postings.') : null),
                Select::make('normal_balance')
                    ->options(NormalBalance::options())
                    ->required()
                    ->disabled(fn (?ChartOfAccount $record): bool => $record !== null && $record->exists && $record->hasPostings())
                    ->helperText(fn (?ChartOfAccount $record): ?string => $record !== null && $record->exists && $record->hasPostings() ? __('Normal balance cannot be changed once the account has postings.') : null),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Toggle::make('is_cash_equivalent'),
                Toggle::make('is_postable')
                    ->default(true)
                    ->helperText(__('Changing this may cause the accounting service to reject transactions.')),
                Toggle::make('is_system')
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_active')
                    ->default(true),
                Textarea::make('description')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('normal_balance'),
                IconColumn::make('is_cash_equivalent')
                    ->boolean(),
                IconColumn::make('is_postable')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountType::options()),
                TernaryFilter::make('is_active')
                    ->default(true),
                TernaryFilter::make('is_postable'),
                TernaryFilter::make('is_system'),
            ])
            ->groups([
                Group::make('type')
                    ->label(__('Type')),
            ])
            ->defaultSort('code')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChartOfAccounts::route('/'),
            'create' => CreateChartOfAccount::route('/create'),
            'view' => ViewChartOfAccount::route('/{record}'),
            'edit' => EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
