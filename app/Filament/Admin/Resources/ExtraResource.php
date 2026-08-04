<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AccountType;
use App\Enums\ExtraPricingUnit;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\Extra;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

class ExtraResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Extra::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('bookings.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('bookings.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('bookings.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof Extra)) {
            return false;
        }

        // On the list the count is eager-loaded by withCount, so the check is
        // free; elsewhere (e.g. the edit page) fall back to a query.
        if ($record->hasAttribute('booking_extras_count')) {
            return $record->booking_extras_count === 0
                && (Auth::user()?->can('bookings.manage') ?? false);
        }

        if ($record->hasBeenSold()) {
            return false;
        }

        return Auth::user()?->can('bookings.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Extra $record): bool => $record?->exists === true && $record->hasBeenSold())
                    ->helperText(fn (?Extra $record) => $record?->exists === true && $record->hasBeenSold() ? __('Extra code is frozen once it has been sold on a booking.') : null),
                Select::make('pricing_unit')
                    ->options(ExtraPricingUnit::options())
                    ->required(),
                TextInput::make('unit_price')->numeric()->required()->prefix('DZD'),
                Select::make('ledger_account_id')
                    ->relationship('ledgerAccount', 'name', fn (Builder $query): Builder => $query->where('is_postable', true)->where('type', AccountType::Revenue))
                    ->searchable()
                    ->preload()
                    ->required(),
                Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->sortable(),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('pricing_unit')
                    ->formatStateUsing(fn (string $state): string => ExtraPricingUnit::tryFrom($state)?->getLabel() ?? $state)
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('booking_extras_count')
                    ->counts('bookingExtras')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('pricing_unit')
                    ->options(ExtraPricingUnit::options()),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('bookingExtras'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Extra $record): bool => $record->booking_extras_count > 0),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ExtraResource\Pages\ListExtras::route('/'),
            'create' => ExtraResource\Pages\CreateExtra::route('/create'),
            'view' => ExtraResource\Pages\ViewExtra::route('/{record}'),
            'edit' => ExtraResource\Pages\EditExtra::route('/{record}/edit'),
        ];
    }
}
