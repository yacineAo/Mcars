<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Jobs\ExportJob;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;
use App\Models\PendingExport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ReportsHubPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected string $view = 'filament.admin.pages.reports-hub';

    protected static ?string $title = 'Reports Hub';

    protected static ?string $slug = 'reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'report_type' => ReportType::ProfitAndLoss->value,
            'from' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'to' => CarbonImmutable::today()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Select::make('report_type')
                    ->label('Report Type')
                    ->options(ReportType::options())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetEntityFilter()),
                Select::make('branch_id')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
                DatePicker::make('from')
                    ->label('From')
                    ->required(),
                DatePicker::make('to')
                    ->label('To')
                    ->required(),
                Select::make('customer_id')
                    ->label('Customer')
                    ->placeholder('All customers (top list)')
                    ->searchable()
                    ->options(fn () => Customer::query()
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->code} — {$c->first_name} {$c->last_name}"]) ?? [])
                    ->visible(fn (): bool => ($this->data['report_type'] ?? '') === ReportType::CustomerReport->value),
                Select::make('car_id')
                    ->label('Car')
                    ->placeholder('All cars (fleet overview)')
                    ->searchable()
                    ->options(fn () => Car::query()
                        ->orderBy('registration_number')
                        ->get()
                        ->mapWithKeys(fn (Car $c) => [$c->id => "{$c->registration_number} — {$c->brand} {$c->model}"]) ?? [])
                    ->visible(fn (): bool => ($this->data['report_type'] ?? '') === ReportType::FleetProfitability->value),
                Select::make('car_owner_id')
                    ->label('Car Owner')
                    ->required()
                    ->searchable()
                    ->options(fn () => CarOwner::query()
                        ->where('is_active', true)
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (CarOwner $o) => [$o->id => "{$o->first_name} {$o->last_name}"]) ?? [])
                    ->visible(fn (): bool => ($this->data['report_type'] ?? '') === ReportType::OwnerStatement->value),
            ])
            ->statePath('data');
    }

    public function export(string $reportType, string $format): void
    {
        $reportTypeEnum = ReportType::tryFrom($reportType);
        $formatEnum = ExportFormat::tryFrom($format);

        if ($reportTypeEnum === null || $formatEnum === null) {
            Notification::make()->title('Invalid export parameters')->danger()->send();

            return;
        }

        $data = $this->form->getState();
        $branchId = $data['branch_id'] ?? null;
        $user = Auth::user();

        $parameters = [
            'branch_id' => $branchId,
            'from' => $data['from'] ?? CarbonImmutable::today()->startOfMonth()->toDateString(),
            'to' => $data['to'] ?? CarbonImmutable::today()->endOfMonth()->toDateString(),
        ];

        if ($reportTypeEnum === ReportType::CustomerReport && ! empty($data['customer_id'])) {
            $parameters['customer_id'] = (int) $data['customer_id'];
        }

        if ($reportTypeEnum === ReportType::FleetProfitability && ! empty($data['car_id'])) {
            $parameters['car_id'] = (int) $data['car_id'];
        }

        if ($reportTypeEnum === ReportType::OwnerStatement) {
            if (empty($data['car_owner_id'])) {
                Notification::make()
                    ->title('Select a car owner')
                    ->danger()
                    ->send();

                return;
            }
            $parameters['car_owner_id'] = (int) $data['car_owner_id'];
        }

        if ($reportTypeEnum === ReportType::ReceivablesAgeing) {
            $parameters['from'] = null;
            $parameters['to'] = null;
        }

        $pendingExport = new PendingExport;
        $pendingExport->branch_id = $branchId;
        $pendingExport->user_id = $user->id;
        $pendingExport->report_type = $reportTypeEnum;
        $pendingExport->format = $formatEnum;
        /** @var array<string, mixed> $parameters */
        $pendingExport->parameters = $parameters;
        $pendingExport->status = 'pending';
        $pendingExport->save();

        ExportJob::dispatch($pendingExport, $user->id);

        Notification::make()
            ->title($formatEnum->getLabel().' export queued')
            ->body("Your {$reportTypeEnum->getLabel()} report is being generated and will be available for download shortly.")
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PendingExport::query()->where('user_id', Auth::id()))
            ->columns([
                TextColumn::make('report_type')
                    ->label('Report')
                    ->sortable(),
                TextColumn::make('format')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? round($state / 1024, 1).' KB' : '-'),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (PendingExport $record): ?string => $record->isCompleted() ? route('exports.download', $record->id) : null)
                    ->visible(fn (PendingExport $record): bool => $record->isCompleted()),
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (PendingExport $record): void {
                        $record->markAsProcessing();
                        ExportJob::dispatch($record, Auth::id());
                        Notification::make()->title('Export re-queued')->success()->send();
                    })
                    ->visible(fn (PendingExport $record): bool => $record->isFailed()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function getActiveReportType(): string
    {
        return $this->data['report_type'] ?? ReportType::ProfitAndLoss->value;
    }

    private function resetEntityFilter(): void
    {
        unset($this->data['customer_id'], $this->data['car_id'], $this->data['car_owner_id']);
    }
}
