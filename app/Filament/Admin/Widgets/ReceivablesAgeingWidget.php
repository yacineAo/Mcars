<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How old the money owed to us is (REQ-18).
 *
 * Ageing is always as-of today — an "as at last month" ageing is a different report —
 * so this ignores the dashboard date range but honours the branch filter.
 */
class ReceivablesAgeingWidget extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 12;

    public static function canView(): bool
    {
        return static::canViewFinancials();
    }

    protected function getStats(): array
    {
        $ageing = app(ReportService::class)->receivablesAgeing($this->reportBranchId());

        return [
            Stat::make('AR (0-30 Days)', number_format($ageing['0_30'], 2).' DZD')
                ->color('success'),
            Stat::make('AR (31-60 Days)', number_format($ageing['31_60'], 2).' DZD')
                ->color('warning'),
            Stat::make('AR (61-90 Days)', number_format($ageing['61_90'], 2).' DZD')
                ->color('danger'),
            Stat::make('AR (90+ Days)', number_format($ageing['90_plus'], 2).' DZD')
                ->color('danger'),
        ];
    }
}
