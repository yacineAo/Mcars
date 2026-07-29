<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\ViewAction;
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
    use TranslatesModelLabel;

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
     * A user without branches.view_all is pinned to their own branch server-side,
     * regardless of any filter they submit.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['alertRule', 'user', 'branch']);

        $user = Auth::user();

        if ($user !== null && ! $user->can('branches.view_all')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
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
                    ->placeholder('—'),

                TextColumn::make('channel')
                    ->label(__('notifications.resources.notification_log.fields.channel'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('recipient')
                    ->label(__('notifications.resources.notification_log.fields.recipient'))
                    ->searchable()
                    ->limit(32),

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
                    ->placeholder('—'),

                TextColumn::make('branch.name')
                    ->label(__('notifications.resources.notification_log.fields.branch'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(NotificationStatus::options()),
                SelectFilter::make('channel')->options(NotificationChannel::options()),
                Filter::make('failed_only')
                    ->label(__('notifications.resources.notification_log.filters.failed_only'))
                    ->query(fn (Builder $query): Builder => $query->where('status', NotificationStatus::Failed->value)),
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
                    TextEntry::make('channel')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('recipient'),
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
