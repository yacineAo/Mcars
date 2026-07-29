<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CashSessionAuditExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly ?int $branchId,
        private readonly array $parameters,
    ) {}

    public function array(): array
    {
        $data = $this->reportService->cashSessionAudit($this->from, $this->to, $this->branchId);

        if (empty($data)) {
            return [['No sessions found for this period']];
        }

        $rows = [];
        foreach ($data as $session) {
            $rows[] = [
                $session['id'] ?? '',
                $session['opened_at'] ?? '',
                $session['closed_at'] ?? '',
                $session['opened_by'] ?? '',
                $session['opening_float'] ?? 0,
                $session['expected'] ?? 0,
                $session['counted'] ?? 0,
                $session['variance'] ?? 0,
                $session['status'] ?? '',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['#', 'Opened', 'Closed', 'By', 'Float', 'Expected', 'Counted', 'Variance', 'Status'];
    }

    public function title(): string
    {
        return 'Cash Sessions';
    }
}
