<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportDefinitionResource\Pages;

use App\Filament\Admin\Resources\ReportDefinitionResource;
use App\Filament\Admin\Resources\ReportResource;
use App\Models\PendingExport;
use App\Models\ReportDefinition;
use App\Services\Reporting\ReportRequest;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The saved report's run history: the definition and its scope, the schedule
 * with its next run time, then every run that came out of it.
 *
 * A saved report's value is its history — did it fire, did it succeed, was it
 * emailed — and that history was invisible before this page. Runs are strictly
 * read-only here; they are created by the scheduler or the Run now action, never
 * by this page. Each run links to its own report view page, so a failed
 * scheduled run can be opened and retried from the archive without hunting for it.
 */
class ViewReportDefinition extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReportDefinitionResource::class;

    private function definition(): ReportDefinition
    {
        /** @var ReportDefinition $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return $this->definition()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->describeScope();
    }

    protected function getHeaderActions(): array
    {
        return [
            ReportDefinitionResource::runNowAction(),

            EditAction::make()
                ->url(fn (): string => ReportDefinitionResource::getUrl('edit', ['record' => $this->getRecord()])),

            DeleteAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('infolist'),
            EmbeddedTable::make(),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $definition = $this->definition();

        return $schema->components([
            Section::make(__('reports.resources.report_definition.sections.report'))
                ->schema([
                    TextEntry::make('report_type')
                        ->label(__('reports.resources.report_definition.fields.report_type'))
                        ->badge(),
                    TextEntry::make('format')
                        ->label(__('reports.resources.report_definition.fields.format'))
                        ->badge(),
                    TextEntry::make('created_at')
                        ->label(__('reports.resources.report_definition.fields.created_at'))
                        ->dateTime(),
                ])
                ->columns(3),

            Section::make(__('reports.resources.report_definition.sections.scope'))
                ->schema([
                    TextEntry::make('scope')
                        ->hiddenLabel()
                        ->state(fn (): string => $this->describeScope()),
                ]),

            Section::make(__('reports.resources.report_definition.sections.schedule'))
                ->schema([
                    TextEntry::make('schedule_cron')
                        ->label(__('reports.resources.report_definition.fields.schedule_cron'))
                        ->state(fn (): ?string => $definition->schedule_cron)
                        ->placeholder('—'),
                    TextEntry::make('next_run_at')
                        ->label(__('reports.resources.report_definition.fields.next_run_at'))
                        ->state(fn (): ?CarbonImmutable => $definition->nextRunAt())
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    IconEntry::make('schedule_enabled')
                        ->label(__('reports.resources.report_definition.fields.schedule_enabled'))
                        ->boolean(),
                    TextEntry::make('schedule_email')
                        ->label(__('reports.resources.report_definition.fields.schedule_email'))
                        ->state(fn (): ?string => $definition->schedule_email)
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('last_sent_at')
                        ->label(__('reports.resources.report_definition.fields.last_sent_at'))
                        ->dateTime()
                        ->placeholder(__('reports.resources.report_definition.never')),
                ])
                ->columns(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->definition()->pendingExports()->getQuery())
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('reports.resources.report.fields.requested_at'))
                    ->dateTime()
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

                TextColumn::make('format')
                    ->label(__('reports.resources.report.fields.format'))
                    ->badge(),

                TextColumn::make('file_size')
                    ->label(__('reports.resources.report.fields.size'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : round($state / 1024, 1).' KB'),

                TextColumn::make('error_message')
                    ->label(__('reports.view.error'))
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('reports.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PendingExport $record): string => ReportResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->heading(__('reports.resources.report_definition.runs'));
    }

    private function describeScope(): string
    {
        $definition = $this->definition();

        $request = new ReportRequest(
            type: $definition->report_type,
            from: CarbonImmutable::now()->startOfMonth(),
            to: CarbonImmutable::now()->endOfMonth(),
            branchId: $definition->branch_id,
            parameters: $definition->parameters,
        );

        $parts = [$definition->branch_id === null
            ? __('reports.all_branches')
            : ($definition->branch->name ?? '—')];

        $entity = ReportResource::describeScopeEntity($request);

        if ($entity !== null) {
            $parts[] = $entity;
        }

        return implode(' · ', $parts);
    }
}
