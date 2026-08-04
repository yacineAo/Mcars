<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\ReportResource\Pages\CreateReport;
use App\Filament\Admin\Resources\ReportResource\Pages\ListReports;
use App\Filament\Admin\Resources\ReportResource\Pages\ViewReport;
use App\Jobs\ExportJob;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;
use App\Models\PendingExport;
use App\Services\Reporting\ReportRequest;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The single entry point for reports.
 *
 * One `pending_exports` row is one report run: the parameters you asked for, the
 * figures you can read on the view page, and the file the queue produced from them.
 * There is deliberately no second page listing the same rows — see
 * docs/tasks/phase-09-reports.md.
 *
 * Saved schedules live in ReportDefinitionResource, which hangs off this item in the
 * navigation rather than sitting beside it.
 */
class ReportResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = PendingExport::class;

    protected static ?string $slug = 'reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('reports.resources.report.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.resources.report.plural_label');
    }

    /**
     * Every report here is a money report. `reports.view_financials` is the gate for
     * revenue, profit, cash flow and receivables everywhere else in the system, and a
     * receptionist who cannot see the figures on a dashboard must not be able to
     * queue them as a PDF either.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    /**
     * You see your own runs; `branches.view_all` widens that to everyone's.
     *
     * This is the same rule ExportController applies to the download itself — the
     * list must not offer a row whose file the user would then be refused.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                ! (Auth::user()?->can('branches.view_all') ?? false),
                fn (Builder $query) => $query->where('user_id', Auth::id()),
            );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('reports.resources.report.sections.report'))
                ->description(__('reports.resources.report.sections.report_hint'))
                ->schema([
                    ToggleButtons::make('report_type')
                        ->hiddenLabel()
                        ->options(ReportType::class)
                        ->default(ReportType::ProfitAndLoss->value)
                        ->required()
                        ->live()
                        ->columns(4)
                        ->afterStateUpdated(function (callable $set): void {
                            // The entity selects are per-report-type. Carrying a
                            // customer id over into a fleet report would silently
                            // narrow it to nothing.
                            $set('customer_id', null);
                            $set('car_id', null);
                            $set('car_owner_id', null);
                        }),
                ]),

            Section::make(__('reports.resources.report.sections.scope'))
                ->schema([
                    Select::make('branch_id')
                        ->label(__('reports.resources.report.fields.branch'))
                        ->placeholder(__('reports.all_branches'))
                        ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),

                    Select::make('customer_id')
                        ->label(__('reports.resources.report.fields.customer'))
                        ->placeholder(__('reports.resources.report.placeholder_customer'))
                        ->searchable()
                        ->preload()
                        ->options(fn () => Customer::query()
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->code} — {$c->first_name} {$c->last_name}"]))
                        ->visible(fn (callable $get): bool => self::scopeFieldFor($get) === 'customer_id'),

                    Select::make('car_id')
                        ->label(__('reports.resources.report.fields.car'))
                        ->placeholder(__('reports.resources.report.placeholder_car'))
                        ->searchable()
                        ->preload()
                        ->options(fn () => Car::query()
                            ->orderBy('registration_number')
                            ->get()
                            ->mapWithKeys(fn (Car $c) => [$c->id => "{$c->registration_number} — {$c->brand} {$c->model}"]))
                        ->visible(fn (callable $get): bool => self::scopeFieldFor($get) === 'car_id'),

                    Select::make('car_owner_id')
                        ->label(__('reports.resources.report.fields.car_owner'))
                        ->searchable()
                        ->preload()
                        ->options(fn () => CarOwner::query()
                            ->where('is_active', true)
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (CarOwner $o) => [$o->id => "{$o->first_name} {$o->last_name}"]))
                        ->visible(fn (callable $get): bool => self::scopeFieldFor($get) === 'car_owner_id')
                        ->required(fn (callable $get): bool => self::typeFor($get)?->requiresScope() ?? false),
                ])
                ->columns(2),

            Section::make(__('reports.resources.report.sections.period'))
                ->schema([
                    Select::make('period_preset')
                        ->label(__('reports.resources.report.fields.preset'))
                        ->dehydrated(false)
                        ->options([
                            'this_month' => __('reports.presets.this_month'),
                            'last_month' => __('reports.presets.last_month'),
                            'this_quarter' => __('reports.presets.this_quarter'),
                            'year_to_date' => __('reports.presets.year_to_date'),
                            'last_year' => __('reports.presets.last_year'),
                        ])
                        ->default('this_month')
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(function (?string $state, callable $set): void {
                            [$from, $to] = self::resolvePreset($state);

                            $set('from', $from);
                            $set('to', $to);
                        }),

                    DatePicker::make('from')
                        ->label(__('reports.resources.report.fields.from'))
                        ->default(fn () => CarbonImmutable::today()->startOfMonth())
                        ->native(false)
                        ->required(),

                    DatePicker::make('to')
                        ->label(__('reports.resources.report.fields.to'))
                        ->default(fn () => CarbonImmutable::today()->endOfMonth())
                        ->native(false)
                        ->required()
                        ->afterOrEqual('from'),
                ])
                ->columns(3)
                // Receivables ageing is measured against today whatever dates it is
                // handed, so offering a period would promise a scope it cannot honour.
                ->visible(fn (callable $get): bool => self::typeFor($get)?->isPeriodic() ?? true),

            Section::make(__('reports.resources.report.sections.output'))
                ->description(__('reports.resources.report.sections.output_hint'))
                ->schema([
                    ToggleButtons::make('format')
                        ->hiddenLabel()
                        ->options(ExportFormat::class)
                        ->default(ExportFormat::Pdf->value)
                        ->inline()
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_type')
                    ->label(__('reports.resources.report.fields.report_type'))
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('scope')
                    ->label(__('reports.resources.report.fields.scope'))
                    ->state(fn (PendingExport $record): string => self::describeScope($record))
                    ->wrap(),

                TextColumn::make('format')
                    ->label(__('reports.resources.report.fields.format'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('reports.resources.report.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("reports.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('reports.resources.report.fields.requested_by'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),

                TextColumn::make('file_size')
                    ->label(__('reports.resources.report.fields.size'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : round($state / 1024, 1).' KB')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('reports.resources.report.fields.requested_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('report_type')
                    ->label(__('reports.resources.report.fields.report_type'))
                    ->options(ReportType::options()),
                SelectFilter::make('format')
                    ->label(__('reports.resources.report.fields.format'))
                    ->options(ExportFormat::options()),
                SelectFilter::make('status')
                    ->label(__('reports.resources.report.fields.status'))
                    ->options([
                        'pending' => __('reports.statuses.pending'),
                        'processing' => __('reports.statuses.processing'),
                        'completed' => __('reports.statuses.completed'),
                        'failed' => __('reports.statuses.failed'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('reports.actions.open')),
                self::downloadAction(),
                self::retryAction(),
            ])
            // Pending and processing rows resolve themselves on the queue; without
            // this the user reloads the page to find out whether the file landed.
            ->poll('10s')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Shared by the table and the view page so the download button behaves identically
     * in both.
     */
    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('reports.actions.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->url(fn (PendingExport $record): string => route('exports.download', $record->id))
            ->openUrlInNewTab()
            ->visible(fn (PendingExport $record): bool => $record->isCompleted());
    }

    public static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('reports.actions.retry'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->action(function (PendingExport $record): void {
                $record->markAsProcessing();
                ExportJob::dispatch($record, (int) Auth::id());

                Notification::make()
                    ->title(__('reports.notifications.requeued'))
                    ->success()
                    ->send();
            })
            ->visible(fn (PendingExport $record): bool => $record->isFailed());
    }

    /**
     * A one-line answer to "what is this report of?" — the branch, the period and the
     * entity it was narrowed to.
     */
    public static function describeScope(PendingExport $record): string
    {
        $request = ReportRequest::fromPendingExport($record);

        $branch = $record->branch;

        $parts = [$request->branchId === null ? __('reports.all_branches') : ($branch->name ?? '—')];

        $parts[] = $request->type->isPeriodic()
            ? $request->from->format('d/m/Y').' → '.$request->to->format('d/m/Y')
            : __('reports.as_of', ['date' => $record->created_at?->format('d/m/Y') ?? '—']);

        $entity = self::describeScopeEntity($request);

        if ($entity !== null) {
            $parts[] = $entity;
        }

        return implode(' · ', $parts);
    }

    /**
     * The name of the entity a report was narrowed to, or null when it covers the
     * whole set. Returns null rather than "#14" for a deleted entity — a stale id is
     * not information.
     */
    public static function describeScopeEntity(ReportRequest $request): ?string
    {
        $id = $request->scopeId();

        if ($id === null) {
            return null;
        }

        $name = fn (?object $model): ?string => $model === null
            ? null
            : trim($model->first_name.' '.$model->last_name);

        return match ($request->type->scopeField()) {
            'customer_id' => $name(Customer::query()->find($id)),
            'car_id' => Car::query()->find($id)?->registration_number,
            'car_owner_id' => $name(CarOwner::query()->find($id)),
            default => null,
        };
    }

    /**
     * The report type currently selected in the form.
     *
     * ToggleButtons::options(ReportType::class) implies ->enum(), so the state is
     * normally the case itself — but it is still a plain string on the first render
     * before the cast applies. Both have to work, or the scope selects never appear.
     */
    private static function typeFor(callable $get): ?ReportType
    {
        $value = $get('report_type');

        if ($value instanceof ReportType) {
            return $value;
        }

        return is_string($value) ? ReportType::tryFrom($value) : null;
    }

    private static function scopeFieldFor(callable $get): ?string
    {
        return self::typeFor($get)?->scopeField();
    }

    /**
     * @return array{string, string}
     */
    private static function resolvePreset(?string $preset): array
    {
        $today = CarbonImmutable::today();

        [$from, $to] = match ($preset) {
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'year_to_date' => [$today->startOfYear(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'create' => CreateReport::route('/create'),
            'view' => ViewReport::route('/{record}'),
        ];
    }
}
