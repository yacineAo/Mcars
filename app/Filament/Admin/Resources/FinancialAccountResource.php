<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\FinancialAccountType;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\CreateFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\EditFinancialAccount;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\ListFinancialAccounts;
use App\Filament\Admin\Resources\FinancialAccountResource\Pages\ViewFinancialAccount;
use App\Models\FinancialAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

class FinancialAccountResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = FinancialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof FinancialAccount)) {
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
                Select::make('ledger_account_id')
                    ->relationship('ledgerAccount', 'name', fn (Builder $query): Builder => $query->where('is_postable', true))
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?FinancialAccount $record): bool => $record !== null && $record->exists && $record->hasPostings())
                    ->helperText(fn (?FinancialAccount $record) => $record !== null && $record->exists && $record->hasPostings() ? __('Ledger account cannot be changed once the account has postings.') : null),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(FinancialAccountType::options())
                    ->required()
                    ->reactive(),
                Select::make('allowed_payment_methods')
                    ->options(PaymentMethod::options())
                    ->multiple()
                    ->label('Allowed Payment Methods'),
                TextInput::make('account_number')
                    ->maxLength(50)
                    ->visible(fn (callable $get): bool => in_array($get('type'), [FinancialAccountType::Bank->value, FinancialAccountType::Ccp->value, FinancialAccountType::BaridiMob->value], true)),
                TextInput::make('rib')
                    ->maxLength(50)
                    ->visible(fn (callable $get): bool => in_array($get('type'), [FinancialAccountType::Bank->value, FinancialAccountType::Ccp->value, FinancialAccountType::BaridiMob->value], true)),
                TextInput::make('holder_name')
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => in_array($get('type'), [FinancialAccountType::Bank->value, FinancialAccountType::Ccp->value], true)),
                TextInput::make('opening_balance')
                    ->numeric()
                    ->prefix('DZD')
                    ->default(0)
                    ->disabled(fn (?FinancialAccount $record): bool => $record !== null && $record->exists && $record->hasPostings()),
                DatePicker::make('opened_on')
                    ->required(),
                Toggle::make('is_default_for_cash'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * @param Builder<FinancialAccount> $query
     * @return Builder<FinancialAccount>
     */
    public static function withCurrentBalance(Builder $query): Builder
    {
        return $query->addSelect('financial_accounts.*', DB::raw(
            '(SELECT COALESCE(SUM(CASE WHEN t.debit_account_id = financial_accounts.ledger_account_id THEN t.amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN t.credit_account_id = financial_accounts.ledger_account_id THEN t.amount ELSE 0 END), 0) FROM transactions t WHERE t.debit_account_id = financial_accounts.ledger_account_id OR t.credit_account_id = financial_accounts.ledger_account_id) AS current_balance',
        ));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('ledgerAccount.name')
                    ->label('Ledger Account'),
                TextColumn::make('branch.name')
                    ->label('Branch'),
                TextColumn::make('current_balance')
                    ->label(__('Current Balance'))
                    ->money('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                IconColumn::make('is_default_for_cash')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(FinancialAccountType::options()),
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('branch_id')
                    ->relationship('branch', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([])
            ->modifyQueryUsing(fn (Builder $query): Builder => self::withCurrentBalance($query));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialAccounts::route('/'),
            'create' => CreateFinancialAccount::route('/create'),
            'view' => ViewFinancialAccount::route('/{record}'),
            'edit' => EditFinancialAccount::route('/{record}/edit'),
        ];
    }
}
