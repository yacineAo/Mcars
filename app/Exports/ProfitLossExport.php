<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProfitLossExport implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->profitAndLoss($this->from, $this->to, $this->branchId);

        return [
            ['Revenue', $data['revenue']],
            ['Expenses', $data['expenses']],
            ['Net Profit', $data['net_profit']],
        ];
    }

    public function headings(): array
    {
        return [
            'Metric',
            $this->from->format('Y-m-d').' to '.$this->to->format('Y-m-d'),
        ];
    }

    public function title(): string
    {
        return 'P&L';
    }
}
