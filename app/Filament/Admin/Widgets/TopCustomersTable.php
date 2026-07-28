<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Services\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

/**
 * Who is worth the most to the business in the selected period (REQ-18).
 *
 * Ranked by net revenue, so it is gated with the other financial widgets.
 */
class TopCustomersTable extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 11;

    protected static ?string $heading = 'Top Customers by Revenue';

    public static function canView(): bool
    {
        return static::canViewFinancials();
    }

    public function table(Table $table): Table
    {
        [$from, $to] = $this->reportPeriod();

        $rows = collect(app(ReportService::class)->topCustomers($from, $to, $this->reportBranchId()))
            ->keyBy('customer_id');

        return $table
            ->records(fn (): Collection => $rows)
            ->emptyStateHeading('No customer revenue in this period')
            ->columns([
                TextColumn::make('code')
                    ->label('Code'),
                TextColumn::make('name')
                    ->label('Name'),
                TextColumn::make('phone')
                    ->label('Phone'),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->money('DZD'),
            ]);
    }
}
