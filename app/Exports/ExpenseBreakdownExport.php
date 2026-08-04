<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ExpenseBreakdownExport implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->expenseBreakdown($this->from, $this->to, $this->branchId);

        $rows = [];
        foreach ($data as $category => $amount) {
            $rows[] = [$category, $amount];
        }

        return $rows;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Category', 'Amount'];
    }

    public function title(): string
    {
        return 'Expenses';
    }
}
