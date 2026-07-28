<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Cash in vs cash out over the trailing 6 months (REQ-18).
 *
 * Internal transfers are excluded by ReportService — banking the till would otherwise
 * appear as both an inflow and an outflow and double apparent turnover.
 */
class CashFlowChart extends ChartWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Cash Flow (Excl. Internal Transfers)';

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
        $cashIn = [];
        $cashOut = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->subMonths($i);
            $labels[] = $month->format('M Y');

            $flow = $reportService->cashFlow($month->startOfMonth(), $month->endOfMonth(), $branchId);
            $cashIn[] = $flow['cash_in'];
            $cashOut[] = $flow['cash_out'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Cash In (DZD)',
                    'data' => $cashIn,
                    'backgroundColor' => '#10b981',
                ],
                [
                    'label' => 'Cash Out (DZD)',
                    'data' => $cashOut,
                    'backgroundColor' => '#f59e0b',
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
