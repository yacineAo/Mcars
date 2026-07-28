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
 * The fleet's best earners (REQ-01, REQ-11).
 *
 * Rendered straight from ReportService rather than re-derived here, so the profit
 * shown matches the car page and the fleet report exactly.
 */
class TopCarsByProfitTable extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 10;

    protected static ?string $heading = 'Top Cars by Net Profit';

    public static function canView(): bool
    {
        return static::canViewFinancials();
    }

    public function table(Table $table): Table
    {
        [$from, $to] = $this->reportPeriod();

        // carProfitability() is already ordered by net profit descending.
        $rows = collect(app(ReportService::class)->carProfitability($from, $to, $this->reportBranchId()))
            ->take(5)
            ->keyBy('car_id');

        return $table
            ->records(fn (): Collection => $rows)
            ->emptyStateHeading('No car activity in this period')
            ->columns([
                TextColumn::make('registration_number')
                    ->label('Plate'),
                TextColumn::make('brand')
                    ->label('Brand / Model')
                    ->formatStateUsing(fn ($record) => trim(($record['brand'] ?? '').' '.($record['model'] ?? ''))),
                TextColumn::make('revenue')
                    ->money('DZD'),
                TextColumn::make('expenses')
                    ->money('DZD'),
                TextColumn::make('net_profit')
                    ->label('Net Profit')
                    ->money('DZD')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('utilisation_pct')
                    ->label('Utilisation')
                    ->suffix('%'),
            ]);
    }
}
