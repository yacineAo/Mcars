<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ReceivablesAgeingExport implements FromArray, WithHeadings, WithTitle
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly ReportService $reportService,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly ?int $branchId,
        private readonly array $parameters,
    ) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // Receivables ageing is as-of-date, not period-based
        $data = $this->reportService->receivablesAgeing($this->branchId);

        return [
            ['0–30 days', $data['0_30']],
            ['31–60 days', $data['31_60']],
            ['61–90 days', $data['61_90']],
            ['90+ days', $data['90_plus']],
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Bucket', 'Amount'];
    }

    public function title(): string
    {
        return 'Ageing';
    }
}
