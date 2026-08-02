<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ReportDefinitionResource\Pages;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;
use App\Models\ReportDefinition;
use App\Rules\EmailList;
use App\Rules\ValidCronExpression;
use App\Services\Reporting\ScheduledReportRunner;
use BackedEnum;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

class ReportDefinitionResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ReportDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    /**
     * Nested under the Reports item rather than sitting beside it: a saved report is
     * a report you run on a cron, not a second kind of thing to go looking for.
     *
     * Filament matches the parent by its rendered *label*, so this has to be resolved
     * from ReportResource rather than hardcoded — the app runs in French, where the
     * parent item reads "Rapports" and a literal 'Reports' would match nothing.
     */
    public static function getNavigationParentItem(): ?string
    {
        return ReportResource::getNavigationLabel();
    }

    public static function getModelLabel(): string
    {
        return __('reports.resources.report_definition.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.resources.report_definition.plural_label');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->label(__('reports.resources.report_definition.fields.name'))
                ->required()
                ->maxLength(255),

            Select::make('report_type')
                ->label(__('reports.resources.report_definition.fields.report_type'))
                ->options(ReportType::options())
                ->required()
                ->live()
                // Freeze once the definition has run: two different report types
                // under one name would make the history read as one report.
                ->disabled(fn (string $operation, ?ReportDefinition $record): bool => $operation === 'edit' && $record?->hasRuns())
                ->hint(fn (string $operation, ?ReportDefinition $record): ?string => $operation === 'edit' && $record?->hasRuns()
                    ? __('reports.resources.report_definition.fields.report_type_frozen')
                    : null),

            Select::make('format')
                ->label(__('reports.resources.report_definition.fields.format'))
                ->options(ExportFormat::options())
                ->required(),

            Section::make(__('reports.resources.report_definition.sections.scope'))
                ->schema([
                    Select::make('param_branch_id')
                        ->label(__('reports.resources.report_definition.fields.branch'))
                        ->placeholder(__('reports.resources.report_definition.all_branches'))
                        ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),

                    Select::make('param_customer_id')
                        ->label(__('reports.resources.report_definition.fields.customer'))
                        ->placeholder(__('reports.resources.report_definition.placeholder_customer'))
                        ->searchable()
                        ->options(fn () => Customer::query()
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->code} — {$c->first_name} {$c->last_name}"]))
                        ->visible(fn (callable $get): bool => ($get('report_type') ?? '') === ReportType::CustomerReport->value),

                    Select::make('param_car_id')
                        ->label(__('reports.resources.report_definition.fields.car'))
                        ->placeholder(__('reports.resources.report_definition.placeholder_car'))
                        ->searchable()
                        ->options(fn () => Car::query()
                            ->orderBy('registration_number')
                            ->get()
                            ->mapWithKeys(fn (Car $c) => [$c->id => "{$c->registration_number} — {$c->brand} {$c->model}"]))
                        ->visible(fn (callable $get): bool => ($get('report_type') ?? '') === ReportType::FleetProfitability->value),

                    Select::make('param_car_owner_id')
                        ->label(__('reports.resources.report_definition.fields.car_owner'))
                        ->searchable()
                        ->options(fn () => CarOwner::query()
                            ->where('is_active', true)
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (CarOwner $o) => [$o->id => "{$o->first_name} {$o->last_name}"]))
                        ->visible(fn (callable $get): bool => ($get('report_type') ?? '') === ReportType::OwnerStatement->value),
                ])
                ->columns(2),

            Section::make(__('reports.resources.report_definition.sections.schedule'))
                ->schema([
                    TextInput::make('schedule_cron')
                        ->label(__('reports.resources.report_definition.fields.schedule_cron'))
                        ->helperText(__('reports.resources.report_definition.help.cron'))
                        ->placeholder('0 8 * * 1')
                        ->rules([new ValidCronExpression])
                        ->live()
                        ->hint(fn (?string $state): ?string => self::nextRunsPreview($state))
                        ->maxLength(100),

                    TextInput::make('schedule_email')
                        ->label(__('reports.resources.report_definition.fields.schedule_email'))
                        ->helperText(__('reports.resources.report_definition.help.email'))
                        ->rules([new EmailList])
                        ->maxLength(255),

                    Toggle::make('schedule_enabled')
                        ->label(__('reports.resources.report_definition.fields.schedule_enabled')),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('reports.resources.report_definition.fields.name'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('report_type')
                    ->label(__('reports.resources.report_definition.fields.report_type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('format')
                    ->label(__('reports.resources.report_definition.fields.format'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('schedule_cron')
                    ->label(__('reports.resources.report_definition.fields.schedule_cron'))
                    ->sortable(),

                TextColumn::make('schedule_email')
                    ->label(__('reports.resources.report_definition.fields.schedule_email')),

                IconColumn::make('schedule_enabled')
                    ->label(__('reports.resources.report_definition.fields.schedule_enabled'))
                    ->boolean()
                    ->sortable(),

                // Derived from the cron expression, not stored — the schedule
                // screen answers "when does this fire next?" without waiting.
                TextColumn::make('next_run_at')
                    ->label(__('reports.resources.report_definition.fields.next_run_at'))
                    ->getStateUsing(fn (ReportDefinition $record): ?CarbonImmutable => $record->schedule_enabled ? $record->nextRunAt() : null)
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextColumn::make('last_sent_at')
                    ->label(__('reports.resources.report_definition.fields.last_sent_at'))
                    ->sortable()
                    // An enabled schedule that has never produced a run looks like
                    // a working one; this is the one time silence means broken.
                    ->color(fn (ReportDefinition $record): ?string => $record->schedule_enabled && $record->last_sent_at === null ? 'warning' : null)
                    ->getStateUsing(function (ReportDefinition $record): string {
                        if ($record->last_sent_at !== null) {
                            return $record->last_sent_at->format('d/m/Y H:i');
                        }

                        return $record->schedule_enabled
                            ? __('reports.resources.report_definition.never_sent')
                            : __('reports.resources.report_definition.never');
                    }),
            ])
            ->filters([
                SelectFilter::make('report_type')
                    ->options(ReportType::options()),
                SelectFilter::make('format')
                    ->options(ExportFormat::options()),
                TernaryFilter::make('schedule_enabled'),
                SelectFilter::make('schedule_state')
                    ->label(__('reports.resources.report_definition.filters.schedule_state'))
                    ->options([
                        'enabled_never_sent' => __('reports.resources.report_definition.filters.enabled_never_sent'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        ($data['value'] ?? null) === 'enabled_never_sent',
                        fn (Builder $q): Builder => $q->where('schedule_enabled', true)->whereNull('last_sent_at'),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::runNowAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportDefinitions::route('/'),
            'create' => Pages\CreateReportDefinition::route('/create'),
            'view' => Pages\ViewReportDefinition::route('/{record}'),
            'edit' => Pages\EditReportDefinition::route('/{record}/edit'),
        ];
    }

    /**
     * Runs the definition now, exactly as the cron sweep would — the same runner,
     * the same last-month window, the same email — and takes the user to the new
     * run so they can watch it generate and download it.
     */
    public static function runNowAction(): Action
    {
        return Action::make('run_now')
            ->label(__('reports.resources.report_definition.actions.run_now'))
            ->icon('heroicon-o-play')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('reports.resources.report_definition.actions.run_now_confirm'))
            ->action(function (Action $action, ReportDefinition $record): void {
                $run = app(ScheduledReportRunner::class)->run($record, CarbonImmutable::now());

                Notification::make()
                    ->title(__('reports.resources.report_definition.actions.run_now_notification'))
                    ->success()
                    ->send();

                $action->getLivewire()->redirect(ReportResource::getUrl('view', ['record' => $run]));
            });
    }

    /**
     * The next few run times of a cron expression, as a hint under the field, or
     * nothing until the operator types a valid one.
     */
    private static function nextRunsPreview(?string $cron): ?string
    {
        if ($cron === null || $cron === '') {
            return null;
        }

        if (! CronExpression::isValidExpression($cron)) {
            return __('reports.resources.report_definition.help.cron_invalid');
        }

        $times = array_map(
            fn (CarbonImmutable $time): string => $time->format('d/m/Y H:i'),
            ReportDefinition::runTimes($cron, 3),
        );

        return __('reports.resources.report_definition.help.next_runs', [
            'times' => implode(' · ', $times),
        ]);
    }
}
