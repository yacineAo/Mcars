<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BlockReason;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\CarBlock;
use App\Services\Booking\CarBlockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use UnitEnum;

class CarBlockResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarBlock::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    /**
     * Blocking a car removes it from sale; unblocking puts it back. Every staff
     * role that works bookings reads the list — including the maintenance
     * officer, whose visibility-matrix row on Bookings is scoped to blocks only.
     * Deciding one is the desk's call (bookings.operate); an accountant audits.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        // canAny(), not can([...]) — the array form is AND here, not OR, so a
        // maintenance officer holding only fleet.manage_maintenance would be
        // locked out of the list.
        return $user !== null
            && $user->canAny(['bookings.view', 'fleet.manage_maintenance']);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->label(__('car_blocks.fields.car'))
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->preload()
                    ->required()
                    // Moving a block to a different car is a delete-and-recreate,
                    // not an edit — it changes what was validated against.
                    ->disabled(fn (?CarBlock $record): bool => $record !== null),
                Select::make('reason')
                    ->label(__('car_blocks.fields.reason'))
                    ->options(BlockReason::options())
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label(__('car_blocks.fields.starts_at'))
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label(__('car_blocks.fields.ends_at'))
                    ->required()
                    // The DB enforces ends_at > starts_at; the form says so first.
                    ->rule('after:starts_at'),
                Select::make('maintenance_log_id')
                    ->label(__('car_blocks.fields.maintenance_log'))
                    ->relationship('maintenanceLog', 'id')
                    ->nullable(),
                Textarea::make('notes')
                    ->label(__('car_blocks.fields.notes')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->label(__('car_blocks.fields.car'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('reason')
                    ->label(__('car_blocks.fields.reason'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('car_blocks.fields.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('car_blocks.fields.ends_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('car_blocks.columns.state'))
                    ->badge()
                    ->color(fn (CarBlock $record): string => match (true) {
                        $record->isActiveNow() => 'success',
                        $record->isUpcoming() => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (CarBlock $record): string => match (true) {
                        $record->isActiveNow() => __('car_blocks.columns.state_active'),
                        $record->isUpcoming() => __('car_blocks.columns.state_upcoming'),
                        default => __('car_blocks.columns.state_ended'),
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('car_blocks.filters.state'))
                    ->options([
                        'active' => __('car_blocks.filters.state_options.active'),
                        'upcoming' => __('car_blocks.filters.state_options.upcoming'),
                        'ended' => __('car_blocks.filters.state_options.ended'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->where('starts_at', '<=', now())->where('ends_at', '>', now()),
                            'upcoming' => $query->where('starts_at', '>', now()),
                            'ended' => $query->where('ends_at', '<=', now()),
                            default => $query,
                        };
                    }),
                SelectFilter::make('car_id')
                    ->label(__('car_blocks.filters.car'))
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('reason')
                    ->label(__('car_blocks.filters.reason'))
                    ->options(BlockReason::options()),
                Filter::make('window')
                    ->label(__('car_blocks.filters.window'))
                    ->form([
                        DatePicker::make('from')->label(__('car_blocks.filters.window_from')),
                        DatePicker::make('to')->label(__('car_blocks.filters.window_to')),
                    ])
                    // Half-open window semantics: a block overlaps the chosen
                    // range when it starts before the range ends and ends after
                    // the range starts.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, string $date): Builder => $q->where('ends_at', '>', $date))
                        ->when($data['to'], fn (Builder $q, string $date): Builder => $q->where('starts_at', '<', Carbon::parse($date)->addDay()))),
            ])
            ->recordActions([
                Action::make('unblock')
                    ->label(fn (CarBlock $record): string => $record->isUpcoming()
                        ? __('car_blocks.actions.cancel')
                        : __('car_blocks.actions.unblock'))
                    ->icon('heroicon-o-check-circle')
                    ->color(fn (CarBlock $record): string => $record->isUpcoming() ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(function (CarBlock $record): void {
                        try {
                            $service = app(CarBlockService::class);

                            // A block that has not started is cancelled, never
                            // ended — ending it would leave an inverted window.
                            if ($record->isUpcoming()) {
                                $service->cancel($record);
                                $title = __('car_blocks.actions.cancelled');
                            } else {
                                $service->endEarly($record);
                                $title = __('car_blocks.actions.unblocked');
                            }
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($title)
                            ->send();
                    })
                    ->visible(fn (CarBlock $record): bool => ! $record->isEnded()),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => CarBlockResource\Pages\ListCarBlocks::route('/'),
            'create' => CarBlockResource\Pages\CreateCarBlock::route('/create'),
            'view' => CarBlockResource\Pages\ViewCarBlock::route('/{record}'),
            'edit' => CarBlockResource\Pages\EditCarBlock::route('/{record}/edit'),
        ];
    }
}
