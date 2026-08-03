<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Filament\Admin\Resources\NotificationLogResource\Pages;
use App\Models\AlertRule;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The delivery audit trail — view only.
 *
 * Never writable from the UI. These rows are what deduplication reads, so editing
 * or deleting one would silently reopen the window for a subject that was in fact
 * already alerted (ADR-012).
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 21;

    public static function getModelLabel(): string
    {
        return __('notifications.resources.notification_log.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.resources.notification_log.plural_label');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('alerts.view_logs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * A user without branches.view_all is pinned to their accessible branches
     * server-side, regardless of any filter they submit. Same rule as
     * ActivityLogResource — the resolution order is branch_user pivot first,
     * then the home branch, then deny (see User::accessibleBranchIds()).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['alertRule', 'user', 'branch', 'related']);

        $user = Auth::user();

        if ($user !== null && ! $user->can('branches.view_all')) {
            $ids = $user->accessibleBranchIds();

            if ($ids !== []) {
                $query->whereIn('branch_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * The view-page URL for whatever a row is about, when that subject has its
     * own resource with a view page. Null — and therefore no link — for subjects
     * that only have a list/edit page (CarDocument, MaintenanceSchedule, ...) or
     * no resource at all.
     */
    public static function subjectUrl(NotificationLog $record): ?string
    {
        $related = $record->related;

        if (! $related instanceof Model) {
            return null;
        }

        $resource = Filament::getModelResource($related::class);

        if ($resource === null || ! isset($resource::getPages()['view'])) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $related], panel: 'admin');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('notifications.resources.notification_log.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('alertRule.type')
                    ->label(__('notifications.resources.notification_log.fields.type'))
                    ->badge()
                    ->placeholder('—')
                    // One click back to the rule that produced the delivery,
                    // mirroring the "View deliveries" row action on AlertRuleResource.
                    ->url(fn (NotificationLog $record): ?string => $record->alert_rule_id === null
                        ? null
                        : AlertRuleResource::getUrl('edit', ['record' => $record->alert_rule_id], panel: 'admin')),

                TextColumn::make('channel')
                    ->label(__('notifications.resources.notification_log.fields.channel'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('recipient')
                    ->label(__('notifications.resources.notification_log.fields.recipient'))
                    // In-app rows store the bare user id; the human is the point.
                    // External addresses (mail) have no user and fall back to the
                    // raw recipient. Both columns stay searchable.
                    ->searchable(['recipient', 'user.name'])
                    ->limit(32)
                    ->formatStateUsing(fn (?string $state, NotificationLog $record): string => $record->user->name ?? (string) $state),

                TextColumn::make('status')
                    ->label(__('notifications.resources.notification_log.fields.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('attempts')
                    ->label(__('notifications.resources.notification_log.fields.attempts'))
                    ->alignCenter(),

                TextColumn::make('related_type')
                    ->label(__('notifications.resources.notification_log.fields.subject'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                    ->placeholder('—')
                    // The subject is why the row exists — make it navigable.
                    ->url(fn (NotificationLog $record): ?string => self::subjectUrl($record)),

                TextColumn::make('branch.name')
                    ->label(__('notifications.resources.notification_log.fields.branch'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('alert_rule_id')
                    ->label(__('notifications.resources.notification_log.filters.alert_rule'))
                    // Options keyed by id, labelled with the alert type, so the
                    // pre-filtered "View deliveries" link (AlertRuleResource) and
                    // manual filtering share one vocabulary. Options are mapped
                    // to the enum's translated label because Select rejects enum
                    // instances as labels.
                    ->options(fn (): array => AlertRule::query()
                        ->orderBy('type')
                        ->get()
                        ->mapWithKeys(fn (AlertRule $rule): array => [
                            $rule->getKey() => $rule->type->getLabel(),
                        ])
                        ->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')->options(NotificationStatus::options()),
                SelectFilter::make('channel')->options(NotificationChannel::options()),
                Filter::make('failed_only')
                    ->label(__('notifications.resources.notification_log.filters.failed_only'))
                    ->query(fn (Builder $query): Builder => $query->where('status', NotificationStatus::Failed->value)),
                // The first thing anyone reaches for on an append-only table:
                // a window over created_at is how you ask "what happened since".
                Filter::make('created_at')
                    ->label(__('notifications.resources.notification_log.filters.date_range'))
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                // A cross-branch user gets a branch selector; a branch-pinned user
                // is scoped server-side, so offering the selector would pretend
                // they can choose what backend prohibits (see getEloquentQuery).
                SelectFilter::make('branch_id')
                    ->label(__('notifications.resources.notification_log.filters.branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => (bool) (Auth::user()?->can('branches.view_all') ?? false)),
                // Filter by the type of rule that fired. Unlike alert_rule_id this
                // does not pin a specific rule — "all BookingOverdue deliveries"
                // regardless of which branch's rule produced them.
                SelectFilter::make('alert_rule_type')
                    ->label(__('notifications.resources.notification_log.filters.alert_type'))
                    ->options(fn (): array => AlertType::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'alertRule',
                            fn (Builder $rule): Builder => $rule->where('type', $data['value']),
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('notifications.resources.notification_log.sections.delivery'))
                ->schema([
                    TextEntry::make('related_type')
                        ->label(__('notifications.resources.notification_log.fields.subject'))
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                        ->placeholder('—')
                        ->url(fn (NotificationLog $record): ?string => self::subjectUrl($record)),
                    TextEntry::make('alertRule.type')
                        ->label(__('notifications.resources.notification_log.fields.type'))
                        ->badge()
                        ->placeholder('—')
                        ->url(fn (NotificationLog $record): ?string => $record->alert_rule_id === null
                            ? null
                            : AlertRuleResource::getUrl('edit', ['record' => $record->alert_rule_id], panel: 'admin')),
                    TextEntry::make('channel')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('user.name')
                        ->label(__('notifications.resources.notification_log.fields.recipient'))
                        ->placeholder('—'),
                    TextEntry::make('recipient')
                        ->label(__('notifications.resources.notification_log.fields.address'))
                        ->placeholder('—'),
                    TextEntry::make('locale'),
                    TextEntry::make('template_key'),
                    TextEntry::make('provider')->placeholder('—'),
                    TextEntry::make('provider_message_id')->placeholder('—'),
                    TextEntry::make('attempts'),
                    TextEntry::make('queued_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('sent_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('delivered_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('failed_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('notifications.resources.notification_log.sections.content'))
                ->schema([
                    KeyValueEntry::make('payload')
                        ->label(__('notifications.resources.notification_log.fields.payload')),
                    TextEntry::make('error')
                        ->label(__('notifications.resources.notification_log.fields.error'))
                        ->placeholder('—')
                        ->color('danger'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
            'view' => Pages\ViewNotificationLog::route('/{record}'),
        ];
    }
}
