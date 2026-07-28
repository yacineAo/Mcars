<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Today at a glance (REQ-01).
 *
 * Fleet counts and cash on hand are operational and visible to everyone. Revenue,
 * expenses and profit are gated — a receptionist runs the day without seeing what the
 * business earns.
 */
class DailyOverviewStats extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Auth::check();
    }

    protected function getStats(): array
    {
        $kpis = app(ReportService::class)->dailyKpis($this->reportBranchId());

        $stats = [
            Stat::make('Available Cars', (string) $kpis['available_cars'])
                ->color('success')
                ->icon('heroicon-o-check-circle'),
            Stat::make('Rented Cars', (string) $kpis['rented_cars'])
                ->color('primary')
                ->icon('heroicon-o-truck'),
            Stat::make('In Maintenance', (string) $kpis['maintenance_cars'])
                ->color('warning')
                ->icon('heroicon-o-wrench'),
            Stat::make('Cash on Hand', number_format($kpis['cash_on_hand'], 2).' DZD')
                ->color('info')
                ->icon('heroicon-o-banknotes'),
        ];

        if (! static::canViewFinancials()) {
            return $stats;
        }

        return [
            ...$stats,
            Stat::make("Today's Revenue", number_format($kpis['daily_revenue'], 2).' DZD')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up'),
            Stat::make("Today's Expenses", number_format($kpis['daily_expenses'], 2).' DZD')
                ->color('danger')
                ->icon('heroicon-o-arrow-trending-down'),
            Stat::make("Today's Net Profit", number_format($kpis['daily_net_profit'], 2).' DZD')
                ->description('7-day revenue trend')
                ->color($kpis['daily_net_profit'] >= 0 ? 'success' : 'danger')
                ->chart($kpis['revenue_sparkline'])
                ->icon('heroicon-o-currency-dollar'),
        ];
    }
}
