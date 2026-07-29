<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class OwnerStatementExport implements FromArray, WithHeadings, WithTitle
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
        $carOwnerId = (int) ($this->parameters['car_owner_id'] ?? 0);
        $data = $this->reportService->ownerStatement($carOwnerId, $this->from, $this->to, $this->branchId);

        if (! is_array($data) || empty($data)) {
            return [['No data available']];
        }

        $rows = [];
        if (isset($data['owner_name'])) {
            $rows[] = ['Owner', $data['owner_name']];
            $rows[] = ['Period', $this->from->format('Y-m-d').' to '.$this->to->format('Y-m-d')];
            $rows[] = [''];

            if (isset($data['installments'])) {
                $rows[] = ['Period', 'Due Date', 'Amount Due', 'Amount Paid', 'Status'];
                foreach ($data['installments'] as $inst) {
                    $rows[] = [
                        $inst['period'] ?? '',
                        $inst['due_date'] ?? '',
                        $inst['amount_due'] ?? 0,
                        $inst['amount_paid'] ?? 0,
                        $inst['status'] ?? '',
                    ];
                }
                $rows[] = [''];
            }

            $rows[] = ['Total Due', $data['total_due'] ?? 0];
            $rows[] = ['Total Paid', $data['total_paid'] ?? 0];
            $rows[] = ['Balance', $data['balance'] ?? 0];
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Field', 'Value'];
    }

    public function title(): string
    {
        return 'Owner Statement';
    }
}
