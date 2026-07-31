<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\TransactionType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Admin\Resources\TransactionResource\Pages\ViewTransaction;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class TransactionResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('debitAccount.name')
                    ->label('Debit'),
                TextColumn::make('creditAccount.name')
                    ->label('Credit'),
                TextColumn::make('amount')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('occurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('posted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Created By'),
                TextColumn::make('description')
                    ->limit(40)
                    ->tooltip(fn (Transaction $record): string => (string) $record->description),
                IconColumn::make('is_reversal')
                    ->label('Reversal')
                    ->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('occurred_on')
                    ->translateLabel()
                    ->form([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('to')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q): Builder => $q->whereDate('occurred_on', '>=', (string) $data['from']))
                            ->when($data['to'] ?? null, fn (Builder $q): Builder => $q->whereDate('occurred_on', '<=', (string) $data['to']));
                    }),
                SelectFilter::make('type')
                    ->translateLabel()
                    ->options(TransactionType::options()),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->translateLabel()
                    ->options(ChartOfAccount::query()->where('is_postable', true)->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, mixed $accountId): Builder => $q->where(
                                fn (Builder $legs): Builder => $legs->where('debit_account_id', $accountId)->orWhere('credit_account_id', $accountId),
                            ),
                        );
                    }),
                TernaryFilter::make('is_reversal')
                    ->label('Reversal')
                    ->translateLabel(),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->translateLabel()
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['debitAccount', 'creditAccount', 'createdBy']))
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'view' => ViewTransaction::route('/{record}'),
        ];
    }
}
