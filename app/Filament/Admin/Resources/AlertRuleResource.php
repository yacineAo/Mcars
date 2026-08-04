<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AlertRuleResource\Pages;
use App\Models\AlertRule;
use App\Rules\UniqueActiveAlertRule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Lead times, channels and recipients — managed by the manager, not hardcoded by
 * a developer (REQ-17).
 *
 * The alert *type* is fixed at creation: it selects the detector, and changing it
 * on an existing rule would orphan every notification_log pointing at the old
 * template key, which is what deduplication reads.
 */
class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('notifications.resources.alert_rule.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.resources.alert_rule.plural_label');
    }

    /** Gated on a permission, never a role list. */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('alerts.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('notifications.resources.alert_rule.sections.what'))
                ->schema([
                    Select::make('type')
                        ->label(__('notifications.resources.alert_rule.fields.type'))
                        ->options(AlertType::options())
                        ->required()
                        // Immutable after creation: the type picks the detector and
                        // seeds template_key, which deduplication keys on.
                        ->disabledOn('edit')
                        ->live()
                        // Mirrors the partial unique indexes: at most one active
                        // rule per (type, branch), and one active global rule per
                        // type. Without this the manager hits a raw QueryException
                        // on the first duplicate — the seeder already created a
                        // global rule for every type.
                        ->rule(fn (?AlertRule $record, $get): UniqueActiveAlertRule => new UniqueActiveAlertRule(
                            $record?->id,
                            $get('branch_id'),
                        ))
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $type = AlertType::tryFrom((string) $state);

                            if ($type === null) {
                                return;
                            }

                            $set('template_key', $type->defaultTemplateKey());
                            $set('days_before', $type->defaultDaysBefore());
                            $set('repeat_every_days', $type->defaultRepeatEveryDays());
                            $set('max_repeats', $type->defaultMaxRepeats());
                        }),

                    Select::make('branch_id')
                        ->label(__('notifications.resources.alert_rule.fields.branch'))
                        ->relationship('branch', 'name')
                        ->searchable()
                        ->preload()
                        // Reads as an explicit choice, not an unset field: an
                        // empty branch_id means "all branches" (see the column).
                        ->placeholder(__('notifications.resources.alert_rule.global'))
                        ->helperText(__('notifications.resources.alert_rule.help.branch')),

                    TextInput::make('template_key')
                        ->label(__('notifications.resources.alert_rule.fields.template_key'))
                        ->required()
                        ->maxLength(100)
                        // Frozen with `type`: deduplication keys on
                        // (template_key, related_type, related_id, channel), so
                        // editing this one field would forget every subject's
                        // alert history and re-alert everyone at the next sweep
                        // (ADR-012). The type seeds it; only the seeder or a
                        // fresh create may set it.
                        ->disabledOn('edit')
                        ->helperText(__('notifications.resources.alert_rule.help.template_key')),

                    Toggle::make('is_active')
                        ->label(__('notifications.resources.alert_rule.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('notifications.resources.alert_rule.sections.when'))
                ->description(__('notifications.resources.alert_rule.help.timing'))
                ->schema([
                    TextInput::make('days_before')
                        ->label(__('notifications.resources.alert_rule.fields.days_before'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText(__('notifications.resources.alert_rule.help.days_before')),

                    TextInput::make('repeat_every_days')
                        ->label(__('notifications.resources.alert_rule.fields.repeat_every_days'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('notifications.resources.alert_rule.help.repeat_every_days')),

                    TextInput::make('max_repeats')
                        ->label(__('notifications.resources.alert_rule.fields.max_repeats'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('notifications.resources.alert_rule.help.max_repeats')),
                ])
                ->columns(3),

            Section::make(__('notifications.resources.alert_rule.sections.who'))
                ->schema([
                    CheckboxList::make('channels')
                        ->label(__('notifications.resources.alert_rule.fields.channels'))
                        ->options(NotificationChannel::options())
                        ->required()
                        ->columns(3),

                    CheckboxList::make('recipient_roles')
                        ->label(__('notifications.resources.alert_rule.fields.recipient_roles'))
                        ->options(UserRole::options())
                        ->required()
                        ->columns(3)
                        ->helperText(__('notifications.resources.alert_rule.help.recipient_roles')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('notifications.resources.alert_rule.fields.type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('branch.name')
                    ->label(__('notifications.resources.alert_rule.fields.branch'))
                    ->placeholder(__('notifications.resources.alert_rule.global'))
                    ->sortable(),

                TextColumn::make('days_before')
                    ->label(__('notifications.resources.alert_rule.fields.days_before'))
                    ->suffix(' d')
                    ->sortable(),

                TextColumn::make('repeat_every_days')
                    ->label(__('notifications.resources.alert_rule.fields.repeat_every_days'))
                    ->placeholder(__('notifications.resources.alert_rule.once'))
                    ->suffix(' d'),

                TextColumn::make('max_repeats')
                    ->label(__('notifications.resources.alert_rule.fields.max_repeats'))
                    ->placeholder('∞'),

                TextColumn::make('channels')
                    ->label(__('notifications.resources.alert_rule.fields.channels'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => NotificationChannel::tryFrom($state)?->getLabel() ?? $state),

                TextColumn::make('recipient_roles')
                    ->label(__('notifications.resources.alert_rule.fields.recipient_roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->getLabel() ?? $state),

                TextColumn::make('template_key')
                    ->label(__('notifications.resources.alert_rule.fields.template_key'))
                    // The dedup key is visible on demand — it is a technical
                    // identifier, not a daily read (ADR-012).
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                IconColumn::make('is_active')
                    ->label(__('notifications.resources.alert_rule.fields.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_by.name')
                    ->label(__('notifications.resources.alert_rule.fields.updated_by'))
                    ->placeholder('—')
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('updated_at')
                    ->label(__('notifications.resources.alert_rule.fields.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(AlertType::options()),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::setActiveAction(),
                self::viewDeliveriesAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('type')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'updatedBy']));
    }

    /**
     * Deactivate / reactivate — the reversible switch for a rule.
     *
     * Deactivating frees the partial unique index, so a manager can create a
     * replacement rule (e.g. a branch override) while keeping the old one for
     * reference. Reactivating re-applies the "one active rule per (type,
     * branch)" constraint.
     */
    public static function setActiveAction(): Action
    {
        return Action::make('set_active')
            ->label(fn (AlertRule $record): string => $record->is_active
                ? __('notifications.resources.alert_rule.actions.deactivate')
                : __('notifications.resources.alert_rule.actions.reactivate'))
            ->icon(fn (AlertRule $record): string => $record->is_active
                ? 'heroicon-o-pause-circle'
                : 'heroicon-o-play-circle')
            ->color(fn (AlertRule $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (AlertRule $record): string => $record->is_active
                ? __('notifications.resources.alert_rule.actions.deactivate_confirm')
                : __('notifications.resources.alert_rule.actions.reactivate_confirm'))
            ->action(function (AlertRule $record): void {
                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active
                        ? __('notifications.resources.alert_rule.notifications.reactivated')
                        : __('notifications.resources.alert_rule.notifications.deactivated'))
                    ->send();
            });
    }

    /**
     * Jump to the delivery audit for this rule, pre-filtered.
     *
     * notification_logs deliberately stays out of this resource as a relation
     * manager — it is an unbounded delivery audit gated on a different
     * permission (alerts.view_logs, not alerts.manage) — so the link navigates
     * to NotificationLogResource instead.
     */
    public static function viewDeliveriesAction(): Action
    {
        return Action::make('view_deliveries')
            ->label(__('notifications.resources.alert_rule.actions.view_deliveries'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->url(fn (AlertRule $record): string => NotificationLogResource::getUrl('index', [
                'tableFilters' => [
                    'alert_rule_id' => ['value' => $record->getKey()],
                ],
            ], panel: 'admin'))
            ->visible(fn (): bool => (bool) (Auth::user()?->can('alerts.view_logs') ?? false));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlertRules::route('/'),
            'create' => Pages\CreateAlertRule::route('/create'),
            'view' => Pages\ViewAlertRule::route('/{record}'),
            'edit' => Pages\EditAlertRule::route('/{record}/edit'),
        ];
    }
}
