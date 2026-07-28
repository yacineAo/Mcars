<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AlertRuleResource\Pages;
use App\Models\AlertRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
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
                        ->helperText(__('notifications.resources.alert_rule.help.branch')),

                    TextInput::make('template_key')
                        ->label(__('notifications.resources.alert_rule.fields.template_key'))
                        ->required()
                        ->maxLength(100)
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

                IconColumn::make('is_active')
                    ->label(__('notifications.resources.alert_rule.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(AlertType::options()),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('type');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlertRules::route('/'),
            'create' => Pages\CreateAlertRule::route('/create'),
            'edit' => Pages\EditAlertRule::route('/{record}/edit'),
        ];
    }
}
