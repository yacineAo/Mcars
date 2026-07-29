<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Exports\CashFlowExport;
use App\Exports\CashSessionAuditExport;
use App\Exports\Contracts\FlattensToSingleSheet;
use App\Exports\CustomerReportExport;
use App\Exports\ExpenseBreakdownExport;
use App\Exports\FleetProfitabilityExport;
use App\Exports\OwnerStatementExport;
use App\Exports\ProfitLossExport;
use App\Exports\ReceivablesAgeingExport;
use App\Mail\ScheduledReportMail;
use App\Models\PendingExport;
use App\Models\User;
use App\Services\Reporting\ReportDataResolver;
use App\Services\Reporting\ReportRequest;
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
use Maatwebsite\Excel\Excel as ExcelWriter;
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

    public function handle(ReportService $reportService, ReportDataResolver $resolver): void
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

            // Same parameter resolution the on-screen report page uses, so the file
            // and the screen cannot cover different periods or branches.
            $request = ReportRequest::fromPendingExport($this->pendingExport);

            $fileName = $this->generateFileName($format);
            $disk = 'private';
            $relativePath = 'exports/'.$fileName;

            $content = match ($format) {
                ExportFormat::Pdf => $this->generatePdf($resolver, $request, $user),
                ExportFormat::Xlsx => $this->generateSpreadsheet($reportService, $request, ExcelWriter::XLSX),
                ExportFormat::Csv => $this->generateSpreadsheet($reportService, $request, ExcelWriter::CSV),
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

    private function generatePdf(ReportDataResolver $resolver, ReportRequest $request, User $user): string
    {
        $pdf = Pdf::setOptions([
            'defaultFont' => 'dejavu sans',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ])->loadView("reports.pdf.{$request->type->value}", [
            'data' => $resolver->resolve($request),
            'from' => $request->from,
            'to' => $request->to,
            'branchId' => $request->branchId,
            'branchName' => $resolver->branchName($request->branchId),
            'user' => $user,
            'generatedAt' => CarbonImmutable::now(),
        ]);

        return $pdf->output();
    }

    /**
     * Excel and CSV share the per-report exporter classes, so a CSV is the same table
     * as the spreadsheet rather than a second, poorer rendering of the same figures.
     *
     * `Excel::raw()` returns the bytes. The previous `Excel::store()` call wrote
     * relative to the `local` disk root while `file_get_contents()` read an absolute
     * `/tmp` path, so every spreadsheet failed — and `tempnam()` had already leaked a
     * file at the suffix-less path it actually created.
     */
    private function generateSpreadsheet(
        ReportService $reportService,
        ReportRequest $request,
        string $writerType,
    ): string {
        $exportClass = $this->resolveExportClass();

        $exporter = new $exportClass(
            $reportService,
            $request->from,
            $request->to,
            $request->branchId,
            $request->parameters,
        );

        // CSV holds one sheet; a multi-sheet export names which one that is.
        if ($writerType === ExcelWriter::CSV && $exporter instanceof FlattensToSingleSheet) {
            $exporter = $exporter->flatSheet();
        }

        return Excel::raw($exporter, $writerType);
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
}
