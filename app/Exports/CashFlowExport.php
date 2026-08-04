<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CashFlowExport implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->cashFlow($this->from, $this->to, $this->branchId);

        return [
            ['Cash In', $data['cash_in']],
            ['Cash Out', $data['cash_out']],
            ['Net Cash Flow', $data['net_cash_flow']],
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Metric', $this->from->format('Y-m-d').' to '.$this->to->format('Y-m-d')];
    }

    public function title(): string
    {
        return 'Cash Flow';
    }
}
