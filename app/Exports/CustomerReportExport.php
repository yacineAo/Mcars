<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomerReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly ReportService $reportService,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly ?int $branchId,
        private readonly array $parameters,
    ) {}

    /** @return array<int, object> */
    public function sheets(): array
    {
        $sheets = [];

        if (isset($this->parameters['customer_id'])) {
            $sheets[] = new CustomerDetailSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters);
        } else {
            $sheets[] = new CustomerTopSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters);
        }

        return $sheets;
    }
}

class CustomerDetailSheet implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->customerStatement((int) $this->parameters['customer_id']);

        return [
            ['Invoiced', $data['invoiced']],
            ['Paid', $data['paid']],
            ['Owed', $data['owed']],
            ['Deposits Held', $data['deposits_held']],
            ['Active Fines', $data['active_fines_count'] ?? 0],
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Metric', 'Value'];
    }

    public function title(): string
    {
        return 'Customer Statement';
    }
}

class CustomerTopSheet implements FromArray, WithHeadings, WithTitle
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
        $customers = $this->reportService->topCustomers($this->from, $this->to, $this->branchId, 100);

        $rows = [];
        foreach ($customers as $customer) {
            $rows[] = [
                $customer['code'],
                $customer['name'],
                $customer['phone'],
                $customer['revenue'],
            ];
        }

        return $rows;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Code', 'Name', 'Phone', 'Revenue'];
    }

    public function title(): string
    {
        return 'Top Customers';
    }
}
