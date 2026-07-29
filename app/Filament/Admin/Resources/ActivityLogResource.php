<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use BackedEnum;
use Filament\Actions\ViewAction;
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

class ActivityLogResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 22;

    public static function getModelLabel(): string
    {
        return __('activity_log.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity_log.resource.plural_label');
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['causer', 'branch']);

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity_log.resource.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label(__('activity_log.resource.fields.log_name'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('activity_log.resource.fields.description'))
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__('activity_log.resource.fields.event'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label(__('activity_log.resource.fields.causer'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label(__('activity_log.resource.fields.subject'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                    ->placeholder('—'),

                TextColumn::make('branch.name')
                    ->label(__('activity_log.resource.fields.branch'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('activity_log.resource.fields.log_name'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->pluck('log_name', 'log_name')
                        ->all()),
                SelectFilter::make('event')
                    ->label(__('activity_log.resource.fields.event'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->pluck('event', 'event')
                        ->all()),
                Filter::make('created_at')
                    ->label(__('activity_log.resource.filters.date_range'))
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['to'], fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('activity_log.resource.sections.details'))
                ->schema([
                    TextEntry::make('created_at')
                        ->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('log_name')->badge(),
                    TextEntry::make('event')->badge()->placeholder('—'),
                    TextEntry::make('description'),
                    TextEntry::make('causer.name')
                        ->label(__('activity_log.resource.fields.causer'))
                        ->placeholder('—'),
                    TextEntry::make('branch.name')
                        ->label(__('activity_log.resource.fields.branch'))
                        ->placeholder('—'),
                    TextEntry::make('subject_type')
                        ->label(__('activity_log.resource.fields.subject'))
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state)),
                    TextEntry::make('subject_id')
                        ->label(__('activity_log.resource.fields.subject_id'))
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('activity_log.resource.sections.changes'))
                ->schema([
                    KeyValueEntry::make('attribute_changes')
                        ->label(__('activity_log.resource.fields.changes'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
