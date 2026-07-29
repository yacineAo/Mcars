<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportResource\Pages;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Filament\Admin\Resources\ReportResource;
use App\Jobs\ExportJob;
use App\Models\PendingExport;
use App\Services\Reporting\ReportDataResolver;
use App\Services\Reporting\ReportRequest;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Renders the report on screen.
 *
 * The figures are recomputed live through ReportDataResolver — the same resolver
 * ExportJob uses — rather than read back out of the generated file. Two consequences
 * worth knowing:
 *
 *  - You get the answer immediately, while the file is still on the queue.
 *  - Reopening an old run shows today's ledger, not the ledger as it stood when the
 *    file was written. The page says so; the file stays the snapshot.
 *
 * Everything is built from Filament's own schema and table components rather than
 * hand-written markup: the panel loads Filament's compiled stylesheet and no custom
 * theme, so bespoke utility classes would simply not exist at runtime.
 */
class ViewReport extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReportResource::class;

    /**
     * Resolved once per request — the summary and the row table both read it.
     *
     * Not named `$data`: ViewRecord already owns that property for the schema state.
     */
    private mixed $reportDataCache = null;

    private bool $reportDataResolved = false;

    private ?string $reportDataError = null;

    public function getTitle(): string
    {
        return $this->reportRequest()->type->getLabel();
    }

    public function getSubheading(): ?string
    {
        return ReportResource::describeScope($this->report());
    }

    /**
     * `getRecord()` is typed to the base Model; everything here needs the export.
     */
    private function report(): PendingExport
    {
        /** @var PendingExport $record */
        $record = $this->getRecord();

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            ReportResource::downloadAction()
                ->button()
                ->color('primary'),

            ActionGroup::make(
                collect(ExportFormat::cases())
                    ->map(fn (ExportFormat $format): Action => Action::make("rerun_{$format->value}")
                        ->label($format->getLabel())
                        ->icon($format->getIcon())
                        ->action(fn () => $this->rerun($format)))
                    ->all(),
            )
                ->label(__('reports.actions.export_as'))
                ->icon('heroicon-o-arrow-down-on-square')
                ->button()
                ->color('gray'),

            ReportResource::retryAction(),

            DeleteAction::make(),
        ];
    }

    /**
     * The page is a summary schema plus, for reports that have rows, a table.
     */
    public function content(Schema $schema): Schema
    {
        $components = [EmbeddedSchema::make('infolist')];

        if ($this->hasRows()) {
            $components[] = EmbeddedTable::make();
        }

        return $schema->components($components);
    }

    // ------------------------------------------------------------------ summary

    public function infolist(Schema $schema): Schema
    {
        if ($this->reportError() !== null) {
            return $schema->components([
                Section::make(__('reports.view.unavailable'))
                    ->description(__('reports.view.unavailable_hint'))
                    ->schema([
                        TextEntry::make('report_error')
                            ->hiddenLabel()
                            ->state(fn (): string => (string) $this->reportError())
                            ->color('danger'),
                    ]),
            ]);
        }

        $summary = $this->summarySection();

        return $schema->components($summary === null
            ? [$this->fileSection()]
            : [$summary, $this->fileSection()]);
    }

    private function summarySection(): ?Component
    {
        $data = $this->reportData();

        if (! is_array($data)) {
            return null;
        }

        [$entries, $columns] = match ($this->reportRequest()->type) {
            ReportType::ProfitAndLoss => [$this->profitAndLossEntries($data), 3],
            ReportType::CashFlow => [$this->cashFlowEntries($data), 3],
            ReportType::ReceivablesAgeing => [$this->receivablesEntries($data), 5],
            ReportType::ExpenseBreakdown => [$this->expenseEntries($data), 2],
            ReportType::CustomerReport => [$this->customerEntries($data), 5],
            ReportType::FleetProfitability => [$this->fleetEntries($data), 4],
            ReportType::OwnerStatement => [$this->ownerEntries($data), 4],
            ReportType::CashSessionAudit => [$this->cashSessionEntries($data), 4],
        };

        if ($entries === []) {
            return null;
        }

        return Section::make(__('reports.view.summary'))
            ->description(__('reports.view.summary_hint'))
            ->icon($this->reportRequest()->type->getIcon())
            ->schema($entries)
            ->columns($columns);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function profitAndLossEntries(array $data): array
    {
        return [
            $this->money('revenue', __('reports.metrics.revenue'), $data['revenue'] ?? 0),
            $this->money('expenses', __('reports.metrics.expenses'), $data['expenses'] ?? 0),
            $this->money('net_profit', __('reports.metrics.net_profit'), $data['net_profit'] ?? 0, signed: true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function cashFlowEntries(array $data): array
    {
        return [
            $this->money('cash_in', __('reports.metrics.cash_in'), $data['cash_in'] ?? 0),
            $this->money('cash_out', __('reports.metrics.cash_out'), $data['cash_out'] ?? 0),
            $this->money('net_cash_flow', __('reports.metrics.net_cash_flow'), $data['net_cash_flow'] ?? 0, signed: true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function receivablesEntries(array $data): array
    {
        $total = array_sum(array_map('floatval', $data));

        return [
            $this->money('bucket_0_30', __('reports.metrics.bucket_0_30'), $data['0_30'] ?? 0),
            $this->money('bucket_31_60', __('reports.metrics.bucket_31_60'), $data['31_60'] ?? 0),
            $this->money('bucket_61_90', __('reports.metrics.bucket_61_90'), $data['61_90'] ?? 0),
            // The oldest bucket is the one that costs money, so it is coloured even
            // when the report is otherwise healthy.
            $this->money('bucket_90_plus', __('reports.metrics.bucket_90_plus'), $data['90_plus'] ?? 0)
                ->color(((float) ($data['90_plus'] ?? 0)) > 0 ? 'danger' : 'gray'),
            $this->money('receivables_total', __('reports.metrics.total_outstanding'), $total)
                ->weight('bold'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function expenseEntries(array $data): array
    {
        return [
            $this->money('expense_total', __('reports.metrics.total_expenses'), array_sum(array_map('floatval', $data)))
                ->weight('bold'),
            $this->plain('expense_categories', __('reports.metrics.categories'), (string) count($data)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function customerEntries(array $data): array
    {
        // A single customer's statement; the all-customers variant is a list, and its
        // figures belong in the table rather than a summary row.
        if (! array_key_exists('invoiced', $data)) {
            $revenue = array_sum(array_column($data, 'revenue'));

            return [
                $this->plain('customer_count', __('reports.metrics.customers'), (string) count($data)),
                $this->money('customer_revenue', __('reports.metrics.revenue'), $revenue)->weight('bold'),
            ];
        }

        return [
            $this->money('invoiced', __('reports.metrics.invoiced'), $data['invoiced'] ?? 0),
            $this->money('paid', __('reports.metrics.paid'), $data['paid'] ?? 0),
            $this->money('owed', __('reports.metrics.owed'), $data['owed'] ?? 0)
                ->color(((float) ($data['owed'] ?? 0)) > 0 ? 'warning' : 'success'),
            // A deposit is a liability held on the customer's behalf, never revenue —
            // it is shown apart from the balance for exactly that reason.
            $this->money('deposits_held', __('reports.metrics.deposits_held'), $data['deposits_held'] ?? 0),
            $this->plain('active_fines', __('reports.metrics.active_fines'), (string) ($data['active_fines_count'] ?? 0)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function fleetEntries(array $data): array
    {
        // Single car: the resolver returns one car's row rather than the fleet roll-up.
        if (array_key_exists('car_id', $data)) {
            return [
                $this->money('car_revenue', __('reports.metrics.revenue'), $data['revenue'] ?? 0),
                $this->money('car_expenses', __('reports.metrics.expenses'), $data['expenses'] ?? 0),
                $this->money('car_net_profit', __('reports.metrics.net_profit'), $data['net_profit'] ?? 0, signed: true),
                $this->plain('car_utilisation', __('reports.metrics.utilisation'), $this->percent($data['utilisation_pct'] ?? 0))
                    ->helperText(__('reports.metrics.utilisation_hint', ['days' => $this->number($data['rental_days'] ?? 0)])),
            ];
        }

        return [
            $this->money('fleet_revenue', __('reports.metrics.revenue'), $data['total_revenue'] ?? 0),
            $this->money('fleet_expenses', __('reports.metrics.expenses'), $data['total_expenses'] ?? 0),
            $this->money('fleet_net_profit', __('reports.metrics.net_profit'), $data['total_net_profit'] ?? 0, signed: true),
            $this->plain('fleet_utilisation', __('reports.metrics.avg_utilisation'), $this->percent($data['avg_utilisation_pct'] ?? 0))
                ->helperText(__('reports.metrics.avg_utilisation_hint')),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Component>
     */
    private function ownerEntries(array $data): array
    {
        return [
            $this->plain('owner_name', __('reports.metrics.owner'), (string) ($data['owner_name'] ?? '—')),
            $this->money('owner_due', __('reports.metrics.total_due'), $data['total_due'] ?? 0),
            $this->money('owner_paid', __('reports.metrics.total_paid'), $data['total_paid'] ?? 0),
            $this->money('owner_balance', __('reports.metrics.balance'), $data['balance'] ?? 0, signed: true)
                ->helperText(__('reports.metrics.balance_hint')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return list<Component>
     */
    private function cashSessionEntries(array $data): array
    {
        $variance = array_sum(array_column($data, 'variance'));
        $short = count(array_filter($data, fn (array $s): bool => (float) $s['variance'] < 0));

        return [
            $this->plain('session_count', __('reports.metrics.sessions'), (string) count($data)),
            $this->plain('sessions_short', __('reports.metrics.sessions_short'), (string) $short)
                ->color($short > 0 ? 'danger' : 'success'),
            $this->money('session_variance', __('reports.metrics.net_variance'), $variance, signed: true)
                ->helperText(__('reports.metrics.net_variance_hint')),
        ];
    }

    /**
     * Provenance of the downloadable file, kept apart from the figures so the two are
     * never confused: the numbers above are live, the file below is a snapshot.
     */
    private function fileSection(): Component
    {
        $record = $this->report();

        return Section::make(__('reports.view.file'))
            ->description(__('reports.view.file_hint'))
            ->collapsed()
            ->schema([
                TextEntry::make('status')
                    ->label(__('reports.resources.report.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("reports.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('format')
                    ->label(__('reports.resources.report.fields.format'))
                    ->badge(),
                TextEntry::make('completed_at')
                    ->label(__('reports.view.generated_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('error_message')
                    ->label(__('reports.view.error'))
                    ->color('danger')
                    ->visible(fn (): bool => $record->isFailed()),
            ])
            ->columns(3);
    }

    // -------------------------------------------------------------------- rows

    /**
     * Whether this report has row data worth a table. P&L and cash flow are three
     * numbers; a table of three numbers is worse than the numbers.
     */
    public function hasRows(): bool
    {
        return $this->reportError() === null && $this->rows() !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $data = $this->reportData();

        if (! is_array($data)) {
            return [];
        }

        return match ($this->reportRequest()->type) {
            ReportType::ExpenseBreakdown => $this->expenseRows($data),
            ReportType::CustomerReport => array_key_exists('invoiced', $data) ? [] : array_values($data),
            ReportType::FleetProfitability => array_key_exists('car_id', $data) ? [] : array_values($data['cars'] ?? []),
            ReportType::OwnerStatement => array_values($data['installments'] ?? []),
            ReportType::CashSessionAudit => array_values($data),
            default => [],
        };
    }

    /**
     * @param array<string, float> $data
     * @return list<array<string, mixed>>
     */
    private function expenseRows(array $data): array
    {
        $total = array_sum(array_map('floatval', $data));

        $rows = [];

        foreach ($data as $category => $amount) {
            $rows[] = [
                'category' => (string) $category,
                'amount' => (float) $amount,
                'share' => $total > 0.0 ? round((float) $amount / $total * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns($this->rowColumns())
            ->heading(__('reports.view.detail'))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(25);
    }

    /**
     * @return list<TextColumn>
     */
    private function rowColumns(): array
    {
        return match ($this->reportRequest()->type) {
            ReportType::ExpenseBreakdown => [
                TextColumn::make('category')->label(__('reports.columns.category'))->wrap(),
                TextColumn::make('amount')->label(__('reports.columns.amount'))->money('DZD')->alignEnd(),
                TextColumn::make('share')->label(__('reports.columns.share'))->suffix('%')->alignEnd(),
            ],
            ReportType::CustomerReport => [
                TextColumn::make('code')->label(__('reports.columns.code')),
                TextColumn::make('name')->label(__('reports.columns.customer'))->wrap(),
                TextColumn::make('phone')->label(__('reports.columns.phone')),
                TextColumn::make('revenue')->label(__('reports.metrics.revenue'))->money('DZD')->alignEnd(),
            ],
            ReportType::FleetProfitability => [
                TextColumn::make('registration_number')->label(__('reports.columns.plate')),
                TextColumn::make('brand')->label(__('reports.columns.car'))
                    ->formatStateUsing(fn (mixed $state, array $record): string => trim($state.' '.($record['model'] ?? ''))),
                TextColumn::make('revenue')->label(__('reports.metrics.revenue'))->money('DZD')->alignEnd(),
                TextColumn::make('expenses')->label(__('reports.metrics.expenses'))->money('DZD')->alignEnd(),
                TextColumn::make('net_profit')->label(__('reports.metrics.net_profit'))->money('DZD')->alignEnd()
                    ->color(fn (mixed $state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('rental_days')->label(__('reports.columns.rental_days'))->alignEnd(),
                TextColumn::make('utilisation_pct')->label(__('reports.metrics.utilisation'))->suffix('%')->alignEnd(),
            ],
            ReportType::OwnerStatement => [
                TextColumn::make('period')->label(__('reports.columns.period')),
                TextColumn::make('due_date')->label(__('reports.columns.due_date'))->date(),
                TextColumn::make('amount_due')->label(__('reports.columns.amount_due'))->money('DZD')->alignEnd(),
                TextColumn::make('amount_paid')->label(__('reports.columns.amount_paid'))->money('DZD')->alignEnd(),
                TextColumn::make('status')->label(__('reports.resources.report.fields.status'))->badge(),
            ],
            ReportType::CashSessionAudit => [
                TextColumn::make('opened_at')->label(__('reports.columns.opened'))->dateTime(),
                TextColumn::make('account_name')->label(__('reports.columns.account')),
                TextColumn::make('opened_by')->label(__('reports.columns.opened_by')),
                TextColumn::make('expected')->label(__('reports.columns.expected'))->money('DZD')->alignEnd(),
                TextColumn::make('counted')->label(__('reports.columns.counted'))->money('DZD')->alignEnd(),
                TextColumn::make('variance')->label(__('reports.columns.variance'))->money('DZD')->alignEnd()
                    ->color(fn (mixed $state): string => match (true) {
                        (float) $state < 0 => 'danger',
                        (float) $state > 0 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('status')->label(__('reports.resources.report.fields.status'))->badge(),
            ],
            default => [],
        };
    }

    // ----------------------------------------------------------------- actions

    /**
     * Re-runs the same parameters in another format as a new run, so the archive keeps
     * one row per file rather than mutating a completed export in place.
     */
    private function rerun(ExportFormat $format): void
    {
        $record = $this->report();

        /** @var PendingExport $copy */
        $copy = PendingExport::create([
            'branch_id' => $record->branch_id,
            'user_id' => Auth::id(),
            'report_type' => $record->report_type,
            'format' => $format,
            'parameters' => $record->parameters,
            'status' => 'pending',
        ]);

        ExportJob::dispatch($copy, (int) Auth::id());

        $this->redirect(ReportResource::getUrl('view', ['record' => $copy]));
    }

    // ------------------------------------------------------------------- data

    public function reportRequest(): ReportRequest
    {
        return ReportRequest::fromPendingExport($this->report());
    }

    /**
     * The report data, or null when it cannot be produced — an owner statement whose
     * owner has since been deleted should say so, not throw.
     */
    public function reportData(): mixed
    {
        if ($this->reportDataResolved) {
            return $this->reportDataCache;
        }

        $this->reportDataResolved = true;

        try {
            $this->reportDataCache = app(ReportDataResolver::class)->resolve($this->reportRequest());
        } catch (Throwable $e) {
            $this->reportDataError = $e->getMessage();
            $this->reportDataCache = null;
        }

        return $this->reportDataCache;
    }

    public function reportError(): ?string
    {
        $this->reportData();

        return $this->reportDataError;
    }

    // -------------------------------------------------------------- formatting

    private function money(string $key, string $label, mixed $value, bool $signed = false): TextEntry
    {
        $amount = (float) $value;

        $entry = TextEntry::make($key)
            ->label($label)
            ->state(number_format($amount, 2, '.', ' ').' DZD')
            ->weight('medium');

        // Only figures that can meaningfully go negative are coloured; a red
        // "expenses" line would read as an error rather than a cost.
        return $signed
            ? $entry->color($amount < 0 ? 'danger' : 'success')
            : $entry;
    }

    private function plain(string $key, string $label, string $value): TextEntry
    {
        return TextEntry::make($key)
            ->label($label)
            ->state($value)
            ->weight('medium');
    }

    private function percent(mixed $value): string
    {
        return number_format((float) $value, 1).'%';
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 1);
    }
}
