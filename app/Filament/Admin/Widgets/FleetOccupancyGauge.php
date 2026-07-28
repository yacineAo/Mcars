<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * How hard the fleet is working (REQ-01, REQ-18).
 *
 * Occupancy is a utilisation figure, not a money figure, so it is not gated behind
 * the financial permission. The denominator is calendar days — see ReportService.
 */
class FleetOccupancyGauge extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 9;

    public static function canView(): bool
    {
        return Auth::check();
    }

    protected function getStats(): array
    {
        [$from, $to] = $this->reportPeriod();

        $occupancy = app(ReportService::class)->occupancyRate($from, $to, $this->reportBranchId());

        return [
            Stat::make('Fleet Occupancy Rate', $occupancy.'%')
                ->description($from->format('d M').' – '.$to->format('d M Y'))
                ->color(match (true) {
                    $occupancy >= 70 => 'success',
                    $occupancy >= 40 => 'warning',
                    default => 'danger',
                })
                ->icon('heroicon-o-chart-pie'),
        ];
    }
}
