<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\MaintenanceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\MaintenanceScheduleResource\Pages;
use App\Models\MaintenanceSchedule;
use App\Services\Fleet\LogMaintenanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MaintenanceScheduleResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = MaintenanceSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Radio::make('applies_to')
                    ->label('Applies to')
                    ->options([
                        'car' => 'Single Car',
                        'category' => 'Car Category',
                    ])
                    ->default('car')
                    ->live()
                    ->hiddenOn('edit'),

                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn (?MaintenanceSchedule $record, callable $get): bool => $record !== null || $get('applies_to') === 'car')
                    ->disabledOn('edit'),

                Select::make('car_category_id')
                    ->relationship('carCategory', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn (?MaintenanceSchedule $record, callable $get): bool => $record !== null || $get('applies_to') === 'category')
                    ->disabledOn('edit'),

                Select::make('task_type')
                    ->options(MaintenanceType::options())
                    ->required()
                    ->disabledOn('edit'),

                TextInput::make('interval_km')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('km')
                    ->helperText('Required if days is empty'),

                TextInput::make('interval_days')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('days')
                    ->helperText('Required if km is empty'),

                DatePicker::make('last_done_at')
                    ->label('Last done (seed only — computed thereafter)'),

                TextInput::make('last_done_odometer')
                    ->numeric()
                    ->suffix('km')
                    ->label('Last done odometer (seed only — computed thereafter)'),

                DatePicker::make('next_due_at')
                    ->label('Next due (seed only — computed thereafter)')
                    ->disabled(fn (?MaintenanceSchedule $record): bool => $record !== null && $record->last_done_at !== null),

                TextInput::make('next_due_odometer')
                    ->numeric()
                    ->suffix('km')
                    ->label('Next due odometer (seed only — computed thereafter)')
                    ->disabled(fn (?MaintenanceSchedule $record): bool => $record !== null && $record->last_done_at !== null),

                TextInput::make('alert_km_before')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix('km')
                    ->helperText('Additional lead time on top of the alert rule'),

                TextInput::make('alert_days_before')
                    ->numeric()
                    ->minValue(0)
                    ->default(7)
                    ->suffix('days')
                    ->helperText('Additional lead time on top of the alert rule'),

                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('car', 'carCategory'))
            ->columns([
                TextColumn::make('car.registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Car'),
                TextColumn::make('carCategory.name')
                    ->sortable()
                    ->searchable()
                    ->label('Category')
                    ->placeholder('—'),
                TextColumn::make('task_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('interval')
                    ->label('Interval')
                    ->getStateUsing(fn (MaintenanceSchedule $record): string => self::formatInterval($record)),
                TextColumn::make('next_due_at')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('days_remaining')
                    ->label('Days rem.')
                    ->getStateUsing(fn (MaintenanceSchedule $record): ?int => self::daysRemaining($record))
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0 => 'danger',
                        $state <= 7 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('next_due_odometer')
                    ->label('Due at')
                    ->suffix(' km')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('km_remaining')
                    ->label('Km rem.')
                    ->getStateUsing(fn (MaintenanceSchedule $record): ?int => self::kmRemaining($record))
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0 => 'danger',
                        $state <= 1000 => 'warning',
                        default => 'success',
                    }),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Filter::make('due_or_overdue')
                    ->label('Due or overdue')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->where(fn (Builder $dq): Builder => $dq
                            ->whereNotNull('next_due_at')
                            ->where('next_due_at', '<=', now()->toDateString()))
                            ->orWhere(fn (Builder $oq): Builder => $oq
                                ->whereNotNull('next_due_odometer')
                                ->whereHas('car', fn (Builder $cq): Builder => $cq
                                    ->whereRaw('cars.odometer >= maintenance_schedules.next_due_odometer')));
                    }))
                    ->toggle(),
                SelectFilter::make('task_type')
                    ->options(MaintenanceType::options()),
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('car_id')
                    ->label('Car')
                    ->relationship('car', 'registration_number')
                    ->searchable(),
                SelectFilter::make('car_category_id')
                    ->label('Category')
                    ->relationship('carCategory', 'name')
                    ->searchable(),
            ])
            ->defaultSort('next_due_at')
            ->headerActions([])
            ->recordActions([
                Action::make('log_maintenance')
                    ->label(__('Log Service Now'))
                    ->icon('heroicon-o-wrench')
                    ->color('primary')
                    ->visible(fn (MaintenanceSchedule $record): bool => $record->car_id !== null
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->form([
                        DatePicker::make('scheduled_for')
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (MaintenanceSchedule $record, array $data): void {
                        app(LogMaintenanceService::class)->log(
                            schedule: $record,
                            scheduledFor: $data['scheduled_for'],
                        );

                        Notification::make()
                            ->success()
                            ->title(__('Maintenance service logged'))
                            ->send();
                    }),

                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (): bool => Auth::user()?->can('fleet.manage_maintenance') ?? false),

                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (MaintenanceSchedule $record): bool => $record->is_active
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->requiresConfirmation()
                    ->action(function (MaintenanceSchedule $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->success()
                            ->title(__('Schedule deactivated'))
                            ->send();
                    }),

                Action::make('reactivate')
                    ->label(__('Reactivate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (MaintenanceSchedule $record): bool => ! $record->is_active
                        && (Auth::user()?->can('fleet.manage_maintenance') ?? false))
                    ->requiresConfirmation()
                    ->action(function (MaintenanceSchedule $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->success()
                            ->title(__('Schedule reactivated'))
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function formatInterval(MaintenanceSchedule $record): string
    {
        $parts = [];

        if ($record->interval_km !== null) {
            $parts[] = number_format($record->interval_km).' km';
        }

        if ($record->interval_days !== null) {
            $parts[] = $record->interval_days.' days';
        }

        return ! empty($parts) ? implode(' / ', $parts) : '—';
    }

    private static function daysRemaining(MaintenanceSchedule $record): ?int
    {
        if ($record->next_due_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($record->next_due_at, false);
    }

    private static function kmRemaining(MaintenanceSchedule $record): ?int
    {
        if ($record->next_due_odometer === null || $record->car === null || $record->car->odometer === null) {
            return null;
        }

        return $record->next_due_odometer - (int) $record->car->odometer;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceSchedules::route('/'),
            'create' => Pages\CreateMaintenanceSchedule::route('/create'),
            'view' => Pages\ViewMaintenanceSchedule::route('/{record}'),
            'edit' => Pages\EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
