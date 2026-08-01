<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\Booking;
use App\Models\ConditionReport;
use App\Services\Booking\ConditionReportService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Check-out / check-in inspections — the evidence behind every closeout charge.
 *
 * A condition report is a point-in-time record of the car's state (odometer, fuel,
 * cleanliness, damage points, photos). It is what PricingService::closeout() charges
 * excess kilometres, fuel shortfall, lateness and cleaning from, and the only defence
 * in a dispute, so:
 *
 * - **There is no delete path.** Not hidden, not permission-gated — absent, like the
 *   ledger itself. A charge stays in the append-only ledger while its justification
 *   must not be able to vanish.
 * - **Readings freeze once the booking is closed.** Amending the odometer after the
 *   closeout charges posted would silently rewrite the justification of a ledger row.
 *   After close, only notes and photos may still be added.
 * - **One report per type per booking.** Two check-in reports would make the charge
 *   basis ambiguous. ConditionReportService owns that guard.
 *
 * Everything reads through `bookings.view`; the desk writes through
 * `bookings.operate`. An accountant may read the evidence but never touch it.
 */
class ConditionReportResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ConditionReport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('bookings.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    /**
     * A report is evidence and cannot be deleted — a report that justified a deposit
     * deduction or an excess-km charge must outlive the dispute. The ledger is
     * append-only and so is this, in effect: the charge row is never edited or
     * deleted, and neither is the report that justified it.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')
                    ->label(__('condition_reports.fields.booking'))
                    ->relationship('booking', 'reference', modifyQueryUsing: function (Builder $query): Builder {
                        $user = Auth::user();

                        if ($user === null || $user->can('branches.view_all')) {
                            return $query;
                        }

                        return $query->whereIn('branch_id', $user->accessibleBranchIds());
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    // Re-pointing a report at another booking would desync the
                    // evidence from the booking it was inspected for. The field
                    // locks once the booking holds a second report: from then on
                    // the pair has a charge basis and neither the direction nor
                    // the booking may change. (Freeze on a completed booking is
                    // covered below — this condition runs before it.)
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && ($record->isFrozen() || $record->hasOtherReport())),
                Select::make('type')
                    ->label(__('condition_reports.fields.type'))
                    ->options(ConditionReportType::options())
                    ->required()
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && ($record->isFrozen() || $record->hasOtherReport())),
                DateTimePicker::make('performed_at')
                    ->label(__('condition_reports.fields.performed_at'))
                    ->required()
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && $record->isFrozen()),
                TextInput::make('odometer')
                    ->label(__('condition_reports.fields.odometer'))
                    ->numeric()
                    ->minValue(0)
                    ->nullable()
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && $record->isFrozen()),
                Select::make('fuel_level')
                    ->label(__('condition_reports.fields.fuel_level'))
                    ->options(FuelLevel::options())
                    ->nullable()
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && $record->isFrozen()),
                Toggle::make('is_clean')
                    ->label(__('condition_reports.fields.clean'))
                    ->default(true)
                    ->disabled(fn (?ConditionReport $record): bool => $record !== null
                        && $record->isFrozen()),
                // Photos stay open after close: attaching more evidence never rewrites
                // the reading that justified a posted charge.
                SpatieMediaLibraryFileUpload::make('photos')
                    ->label(__('condition_reports.fields.photos'))
                    ->disk('private')
                    ->collection('photos')
                    ->multiple()
                    ->image()
                    ->maxSize(8192),
                Textarea::make('notes')
                    ->label(__('condition_reports.fields.notes'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.car.registration_number')
                    ->label(__('condition_reports.fields.car'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('booking.reference')
                    ->label(__('condition_reports.fields.booking'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('condition_reports.fields.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('performed_at')
                    ->label(__('condition_reports.fields.performed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('performedBy.name')
                    ->label(__('condition_reports.fields.performed_by'))
                    ->placeholder('—'),
                IconColumn::make('is_clean')
                    ->label(__('condition_reports.fields.clean'))
                    ->boolean(),
                TextColumn::make('damage_points')
                    ->label(__('condition_reports.fields.damage_points'))
                    // The column is a jsonb array of damage points; the count is what
                    // matters in a list, and the detail belongs on the report itself.
                    ->formatStateUsing(fn ($state): int => is_array($state) ? count($state) : 0)
                    ->badge()
                    ->color(fn (ConditionReport $record): string => count($record->damage_points ?? []) > 0
                        ? 'danger'
                        : 'success'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('condition_reports.filters.type'))
                    ->options(ConditionReportType::options()),
                SelectFilter::make('booking_id')
                    ->label(__('condition_reports.filters.booking'))
                    ->relationship('booking', 'reference')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('car_id')
                    ->label(__('condition_reports.filters.car'))
                    ->relationship('booking.car', 'registration_number')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('damages')
                    ->label(__('condition_reports.filters.damages'))
                    ->options([
                        'damaged' => __('condition_reports.filters.damages_options.damaged'),
                        'clean' => __('condition_reports.filters.damages_options.clean'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'damaged' => $query->where(function (Builder $q): Builder {
                                return $q->where('is_clean', false)
                                    ->orWhereRaw('COALESCE(jsonb_array_length(damage_points), 0) > 0');
                            }),
                            'clean' => $query->where('is_clean', true)
                                ->whereRaw('COALESCE(jsonb_array_length(damage_points), 0) = 0'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('performed_at', 'desc');
    }

    /**
     * A condition report has no branch of its own — it belongs to a booking, which
     * does. A user without `branches.view_all` is pinned to their own branch
     * server-side, regardless of any filter they submit.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['booking.car', 'performedBy']);

        $user = Auth::user();

        if ($user !== null && ! $user->can('branches.view_all')) {
            $ids = $user->accessibleBranchIds();

            if ($ids !== []) {
                $query->whereHas('booking', fn (Builder $q): Builder => $q->whereIn('branch_id', $ids));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ConditionReportResource\Pages\ListConditionReports::route('/'),
            'create' => ConditionReportResource\Pages\CreateConditionReport::route('/create'),
            'view' => ConditionReportResource\Pages\ViewConditionReport::route('/{record}'),
            'edit' => ConditionReportResource\Pages\EditConditionReport::route('/{record}/edit'),
        ];
    }
}
