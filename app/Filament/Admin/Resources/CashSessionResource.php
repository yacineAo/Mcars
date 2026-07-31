<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CashSessionStatus;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CashSessionResource\Pages\CreateCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\EditCashSession;
use App\Filament\Admin\Resources\CashSessionResource\Pages\ListCashSessions;
use App\Filament\Admin\Resources\CashSessionResource\Pages\ViewCashSession;
use App\Models\CashSession;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CashSessionResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    /**
     * The till is operated by receptionists and managers; the variance and the
     * postings are read by whoever can see the financials. A supervisor has
     * neither and therefore does not see the resource at all.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('cash_sessions.operate')
            || $user->can('reports.view_financials')
        );
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('cash_sessions.operate') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof CashSession && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('cash_sessions.operate') ?? false)
            && $record instanceof CashSession
            && self::userCanReachBranch($record->branch_id);
    }

    /**
     * A user without branches.view_all is pinned to their own branch, server-side
     * — the list query, the form's account options and every record-gated page
     * all go through this, so a receptionist can neither see nor operate another
     * branch's till regardless of what they submit.
     */
    public static function userCanReachBranch(?int $branchId): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('branches.view_all')
            || $user->branch_id === $branchId
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('financial_account_id')
                    ->relationship('financialAccount', 'name', fn (Builder $query): Builder => $query->when(
                        ! (Auth::user()?->can('branches.view_all') ?? false),
                        fn (Builder $q): Builder => $q->where('branch_id', Auth::user()?->branch_id),
                    ))
                    ->searchable()
                    ->required(),
                TextInput::make('opening_float')
                    ->numeric()
                    ->prefix('DZD')
                    ->required()
                    ->default(0),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Account'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('opening_float')
                    ->money('DZD'),
                TextColumn::make('counted_amount')
                    ->money('DZD'),
                TextColumn::make('expected')
                    ->label('Expected')
                    ->money('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->state(fn (CashSession $record, ListCashSessions $livewire): string => $livewire->getReconciliations()[(int) $record->id]['expected']),
                TextColumn::make('variance')
                    ->label('Variance')
                    ->money('DZD')
                    ->placeholder('—')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->state(fn (CashSession $record, ListCashSessions $livewire): ?string => $livewire->getReconciliations()[(int) $record->id]['variance'])
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'success',
                        Money::of($state)->isNegative() => 'danger',
                        Money::of($state)->isPositive() => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('openedBy.name')
                    ->label('Opened By'),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->translateLabel()
                    ->options(CashSessionStatus::options())
                    // A session left open overnight is the one thing a manager
                    // needs to spot; closed history is one click away.
                    ->default(CashSessionStatus::Open->value),
                Filter::make('opened_at')
                    ->translateLabel()
                    ->form([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('to')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q): Builder => $q->whereDate('opened_at', '>=', (string) $data['from']))
                            ->when($data['to'] ?? null, fn (Builder $q): Builder => $q->whereDate('opened_at', '<=', (string) $data['to']));
                    }),
                SelectFilter::make('financial_account_id')
                    ->label('Account')
                    ->translateLabel()
                    ->relationship('financialAccount', 'name')
                    ->searchable(),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->translateLabel()
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with(['financialAccount', 'openedBy', 'closedBy', 'branch']);

                $user = auth()->user();
                if ($user !== null && ! $user->can('branches.view_all')) {
                    $query->where('cash_sessions.branch_id', $user->branch_id);
                }

                return $query;
            })
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'create' => CreateCashSession::route('/create'),
            'view' => ViewCashSession::route('/{record}'),
            'edit' => EditCashSession::route('/{record}/edit'),
        ];
    }
}
