<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Net profit over the trailing 12 months (REQ-18).
 */
class NetProfitTrendChart extends ChartWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Net Profit Trend (12 Months)';

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
        $profit = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->subMonths($i);
            $labels[] = $month->format('M Y');
            $profit[] = $reportService->profitAndLoss($month->startOfMonth(), $month->endOfMonth(), $branchId)['net_profit'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Net Profit (DZD)',
                    'data' => $profit,
                    'borderColor' => '#3b82f6',
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
