<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Revenue vs expenses over the trailing 12 months (REQ-18).
 *
 * A trend chart owns its own window, so it follows the branch filter but not the
 * dashboard's date range.
 */
class MonthlyRevenueExpenseChart extends ChartWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Monthly Revenue vs Expenses (12 Months)';

    public static function canView(): bool
    {
        return static::canViewFinancials();
    }

    protected function getData(): array
    {
        $reportService = app(ReportService::class);
        $branchId = $this->reportBranchId();
        $now = CarbonImmutable::today();

        $labels = [];
        $revenue = [];
        $expenses = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->subMonths($i);
            $labels[] = $month->format('M Y');

            $pnl = $reportService->profitAndLoss($month->startOfMonth(), $month->endOfMonth(), $branchId);
            $revenue[] = $pnl['revenue'];
            $expenses[] = $pnl['expenses'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (DZD)',
                    'data' => $revenue,
                    'backgroundColor' => '#10b981',
                ],
                [
                    'label' => 'Expenses (DZD)',
                    'data' => $expenses,
                    'backgroundColor' => '#ef4444',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
