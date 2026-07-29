<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReportResource\Pages;

use App\Enums\ReportType;
use App\Filament\Admin\Resources\ReportResource;
use App\Jobs\ExportJob;
use App\Models\PendingExport;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateReport extends CreateRecord
{
    protected static string $resource = ReportResource::class;

    public function getTitle(): string
    {
        return __('reports.resources.report.create_title');
    }

    public function getSubheading(): ?string
    {
        return __('reports.resources.report.create_subheading');
    }

    /**
     * Flat form fields become the `parameters` payload the queue reads.
     *
     * branch_id is written to both the column and the payload deliberately: the
     * column is what BranchScope filters the archive by, and the payload is what the
     * job reads, because there is no session on the queue to resolve a branch from.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $branchId = $data['branch_id'] ?? null;

        // ToggleButtons::options(ReportType::class) implies ->enum(), so the state
        // arrives as the case itself rather than its value.
        $reportType = $data['report_type'] instanceof ReportType
            ? $data['report_type']
            : ReportType::from((string) $data['report_type']);

        $parameters = ['branch_id' => $branchId];

        if ($reportType->isPeriodic()) {
            $parameters['from'] = $data['from'] ?? null;
            $parameters['to'] = $data['to'] ?? null;
        }

        $scopeField = $reportType->scopeField();

        if ($scopeField !== null && ! empty($data[$scopeField])) {
            $parameters[$scopeField] = (int) $data[$scopeField];
        }

        return [
            'branch_id' => $branchId,
            'user_id' => Auth::id(),
            'report_type' => $data['report_type'],
            'format' => $data['format'],
            'parameters' => $parameters,
            'status' => 'pending',
        ];
    }

    protected function afterCreate(): void
    {
        $record = $this->record;

        if ($record instanceof PendingExport) {
            ExportJob::dispatch($record, (int) Auth::id());
        }
    }

    /**
     * Straight to the figures. The file is still being written on the queue, but the
     * report itself is computed synchronously — making the user wait on the queue to
     * read a number they could already have is the whole reason this section felt
     * like a download manager.
     */
    protected function getRedirectUrl(): string
    {
        return ReportResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('reports.notifications.queued');
    }
}
