<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Exports\CashFlowExport;
use App\Exports\CashSessionAuditExport;
use App\Exports\CustomerReportExport;
use App\Exports\ExpenseBreakdownExport;
use App\Exports\FleetProfitabilityExport;
use App\Exports\OwnerStatementExport;
use App\Exports\ProfitLossExport;
use App\Exports\ReceivablesAgeingExport;
use App\Mail\ScheduledReportMail;
use App\Models\Branch;
use App\Models\PendingExport;
use App\Models\User;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly PendingExport $pendingExport,
        public readonly int $userId,
    ) {
        $this->onQueue('exports');
    }

    public function handle(ReportService $reportService): void
    {
        $this->pendingExport->markAsProcessing();

        try {
            $user = User::find($this->userId);
            if ($user === null) {
                $this->pendingExport->markAsFailed('User not found');

                return;
            }

            /** @var ExportFormat $format */
            $format = $this->pendingExport->format;
            /** @var array<string, mixed> $parameters */
            $parameters = $this->pendingExport->parameters;
            $branchId = array_key_exists('branch_id', $parameters) ? $parameters['branch_id'] : $this->pendingExport->branch_id;
            $from = isset($parameters['from'])
                ? CarbonImmutable::parse($parameters['from'])
                : CarbonImmutable::today()->startOfMonth();
            $to = isset($parameters['to'])
                ? CarbonImmutable::parse($parameters['to'])
                : CarbonImmutable::today()->endOfMonth();

            $fileName = $this->generateFileName($format);
            $disk = 'private';
            $relativePath = 'exports/'.$fileName;

            $content = match ($format) {
                ExportFormat::Pdf => $this->generatePdf($reportService, $from, $to, $branchId, $parameters, $user),
                ExportFormat::Xlsx => $this->generateExcel($reportService, $from, $to, $branchId, $parameters),
                ExportFormat::Csv => $this->generateCsv($reportService, $from, $to, $branchId, $parameters),
            };

            Storage::disk($disk)->put($relativePath, $content);

            $this->pendingExport->markAsCompleted(
                filePath: $relativePath,
                fileName: $fileName,
                fileSize: Storage::disk($disk)->size($relativePath),
            );

            $this->sendScheduledReportEmail();
        } catch (\Throwable $e) {
            Log::error('Export failed', [
                'export_id' => $this->pendingExport->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->pendingExport->markAsFailed($e->getMessage());
        }
    }

    private function sendScheduledReportEmail(): void
    {
        $definitionId = $this->pendingExport->report_definition_id;

        if ($definitionId === null) {
            return;
        }

        $definition = $this->pendingExport->reportDefinition;

        if ($definition === null || $definition->schedule_email === null) {
            return;
        }

        $fileContent = Storage::disk('private')->get($this->pendingExport->file_path);

        if ($fileContent === null) {
            Log::warning('Scheduled report email skipped: file not found', [
                'report_definition_id' => $definitionId,
                'file_path' => $this->pendingExport->file_path,
            ]);

            return;
        }

        Mail::mailer()
            ->to($definition->schedule_email)
            ->send(new ScheduledReportMail(
                reportName: $definition->name,
                fileName: $this->pendingExport->file_name,
                content: $fileContent,
                mimeType: $this->pendingExport->format->mimeType(),
            ));
    }

    private function generateFileName(ExportFormat $format): string
    {
        $type = $this->pendingExport->report_type->value;
        $date = CarbonImmutable::now()->format('Ymd_His');

        return "{$type}_{$date}.{$format->extension()}";
    }

    private function generatePdf(
        ReportService $reportService,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        array $parameters,
        User $user,
    ): string {
        $data = $this->resolveReportData($reportService, $from, $to, $branchId, $parameters);

        $branchName = $this->resolveBranchName($branchId);

        $pdf = Pdf::setOptions([
            'defaultFont' => 'dejavu sans',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ])->loadView("reports.pdf.{$this->pendingExport->report_type->value}", [
            'data' => $data,
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'branchName' => $branchName,
            'user' => $user,
            'generatedAt' => CarbonImmutable::now(),
        ]);

        return $pdf->output();
    }

    private function generateExcel(
        ReportService $reportService,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        array $parameters,
    ): string {
        $exportClass = $this->resolveExportClass();

        $exporter = new $exportClass($reportService, $from, $to, $branchId, $parameters);

        $tempPath = tempnam(sys_get_temp_dir(), 'export_').'.xlsx';

        try {
            Excel::store($exporter, $tempPath, 'local');

            $content = file_get_contents($tempPath);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $content;
    }

    private function generateCsv(
        ReportService $reportService,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        array $parameters,
    ): string {
        $data = $this->resolveReportData($reportService, $from, $to, $branchId, $parameters);
        $rows = $this->flattenDataForCsv($data);

        $output = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);

        return stream_get_contents($output);
    }

    private function resolveBranchName(?int $branchId): string
    {
        if ($branchId === null) {
            return 'All Branches';
        }

        $branch = Branch::find($branchId);

        if ($branch === null) {
            return "Branch #{$branchId}";
        }

        return $branch->name;
    }

    private function resolveReportData(
        ReportService $reportService,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        array $parameters,
    ): mixed {
        return match ($this->pendingExport->report_type) {
            ReportType::ProfitAndLoss => $reportService->profitAndLoss($from, $to, $branchId),
            ReportType::ExpenseBreakdown => $reportService->expenseBreakdown($from, $to, $branchId),
            ReportType::CustomerReport => $parameters['customer_id'] ?? null
                ? $reportService->customerStatement((int) $parameters['customer_id'])
                : $reportService->topCustomers($from, $to, $branchId, 100),
            ReportType::FleetProfitability => $parameters['car_id'] ?? null
                ? $reportService->singleCarProfitability((int) $parameters['car_id'], $from, $to)
                : $reportService->fleetProfitability($from, $to, $branchId),
            ReportType::CashFlow => $reportService->cashFlow($from, $to, $branchId),
            ReportType::OwnerStatement => $reportService->ownerStatement(
                (int) ($parameters['car_owner_id'] ?? 0),
                $from,
                $to,
                $branchId,
            ),
            ReportType::ReceivablesAgeing => $reportService->receivablesAgeing($branchId),
            ReportType::CashSessionAudit => $reportService->cashSessionAudit($from, $to, $branchId),
        };
    }

    private function resolveExportClass(): string
    {
        return match ($this->pendingExport->report_type) {
            ReportType::ProfitAndLoss => ProfitLossExport::class,
            ReportType::ExpenseBreakdown => ExpenseBreakdownExport::class,
            ReportType::CustomerReport => CustomerReportExport::class,
            ReportType::FleetProfitability => FleetProfitabilityExport::class,
            ReportType::CashFlow => CashFlowExport::class,
            ReportType::OwnerStatement => OwnerStatementExport::class,
            ReportType::ReceivablesAgeing => ReceivablesAgeingExport::class,
            ReportType::CashSessionAudit => CashSessionAuditExport::class,
        };
    }

    private function flattenDataForCsv(mixed $data): array
    {
        if (is_array($data) && isset($data['revenue'], $data['expenses'])) {
            return [
                ['Metric', 'Value'],
                ['Revenue', $data['revenue']],
                ['Expenses', $data['expenses']],
                ['Net Profit', $data['net_profit'] ?? ($data['revenue'] - $data['expenses'])],
            ];
        }

        if (is_array($data) && isset($data['cash_in'])) {
            return [
                ['Metric', 'Value'],
                ['Cash In', $data['cash_in']],
                ['Cash Out', $data['cash_out']],
                ['Net Cash Flow', $data['net_cash_flow']],
            ];
        }

        if (is_array($data) && isset($data['total_revenue'])) {
            $rows = [
                ['Registration', 'Brand', 'Model', 'Revenue', 'Expenses', 'Net Profit', 'Rental Days', 'Utilisation %'],
            ];
            foreach ($data['cars'] ?? [] as $car) {
                $rows[] = [
                    $car['registration_number'] ?? '',
                    $car['brand'] ?? '',
                    $car['model'] ?? '',
                    $car['revenue'] ?? 0,
                    $car['expenses'] ?? 0,
                    $car['net_profit'] ?? 0,
                    $car['rental_days'] ?? 0,
                    $car['utilisation_pct'] ?? 0,
                ];
            }

            return $rows;
        }

        if (is_array($data) && isset($data['0_30'])) {
            return [
                ['Bucket', 'Amount'],
                ['0–30 days', $data['0_30']],
                ['31–60 days', $data['31_60']],
                ['61–90 days', $data['61_90']],
                ['90+ days', $data['90_plus']],
            ];
        }

        if (is_array($data) && isset($data['invoiced'])) {
            return [
                ['Metric', 'Value'],
                ['Invoiced', $data['invoiced']],
                ['Paid', $data['paid']],
                ['Owed', $data['owed']],
                ['Deposits Held', $data['deposits_held']],
                ['Active Fines', $data['active_fines_count'] ?? 0],
            ];
        }

        return [['Data', json_encode($data)]];
    }
}
