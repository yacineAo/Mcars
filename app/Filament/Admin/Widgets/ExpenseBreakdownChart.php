<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

/**
 * Where the money went, by expense category (REQ-18). Follows the dashboard's
 * branch and date-range filters.
 */
class ExpenseBreakdownChart extends ChartWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 8;

    protected ?string $heading = 'Expense Breakdown';

    public static function canView(): bool
    {
        return static::canViewFinancials();
    }

    protected function getData(): array
    {
        [$from, $to] = $this->reportPeriod();

        $breakdown = app(ReportService::class)->expenseBreakdown($from, $to, $this->reportBranchId());

        return [
            'datasets' => [
                [
                    'label' => 'Expenses (DZD)',
                    'data' => array_values($breakdown),
                    'backgroundColor' => [
                        '#ef4444',
                        '#f59e0b',
                        '#3b82f6',
                        '#8b5cf6',
                        '#ec4899',
                        '#14b8a6',
                        '#64748b',
                    ],
                ],
            ],
            'labels' => array_keys($breakdown),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
