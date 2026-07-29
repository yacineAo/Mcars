<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Contracts\FlattensToSingleSheet;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class FleetProfitabilityExport implements FlattensToSingleSheet, WithMultipleSheets
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly ?int $branchId,
        private readonly array $parameters,
    ) {}

    public function sheets(): array
    {
        if (isset($this->parameters['car_id'])) {
            return [
                new SingleCarSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters),
            ];
        }

        return [
            new FleetSummarySheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters),
            new FleetDetailSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters),
        ];
    }

    /**
     * The per-car rows, not the summary. A fleet CSV carrying four totals and no cars
     * is the one thing nobody exports it for.
     */
    public function flatSheet(): object
    {
        if (isset($this->parameters['car_id'])) {
            return new SingleCarSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters);
        }

        return new FleetDetailSheet($this->reportService, $this->from, $this->to, $this->branchId, $this->parameters);
    }
}

class FleetSummarySheet implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->fleetProfitability($this->from, $this->to, $this->branchId);

        return [
            ['Total Revenue', $data['total_revenue']],
            ['Total Expenses', $data['total_expenses']],
            ['Total Net Profit', $data['total_net_profit']],
            ['Avg Utilisation', number_format((float) $data['avg_utilisation_pct'], 1).'%'],
        ];
    }

    public function headings(): array
    {
        return ['Metric', 'Value'];
    }

    public function title(): string
    {
        return 'Summary';
    }
}

class FleetDetailSheet implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->fleetProfitability($this->from, $this->to, $this->branchId);

        $rows = [];
        foreach ($data['cars'] as $car) {
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

    public function headings(): array
    {
        return ['Reg No', 'Brand', 'Model', 'Revenue', 'Expenses', 'Net Profit', 'Rental Days', 'Utilisation %'];
    }

    public function title(): string
    {
        return 'Per Car';
    }
}

class SingleCarSheet implements FromArray, WithHeadings, WithTitle
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
        $data = $this->reportService->singleCarProfitability((int) $this->parameters['car_id'], $this->from, $this->to);

        if ($data === null) {
            return [['Car not found']];
        }

        return [
            ['Registration', $data['registration_number']],
            ['Brand', $data['brand']],
            ['Model', $data['model']],
            ['Revenue', $data['revenue']],
            ['Expenses', $data['expenses']],
            ['Net Profit', $data['net_profit']],
            ['Rental Days', $data['rental_days']],
            ['Utilisation', number_format((float) $data['utilisation_pct'], 1).'%'],
        ];
    }

    public function headings(): array
    {
        return ['Metric', 'Value'];
    }

    public function title(): string
    {
        return 'Car Profitability';
    }
}
