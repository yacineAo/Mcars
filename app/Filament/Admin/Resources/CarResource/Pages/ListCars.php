<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCars extends ListRecords
{
    protected static string $resource = CarResource::class;

    /**
     * car_id => month-to-date net profit. Resolved once per request.
     *
     * @var array<int, float>|null
     */
    private ?array $monthToDateNetProfit = null;

    /**
     * Eager loading and the branch pin live in CarResource::getEloquentQuery() rather
     * than in a getTableQuery() override here: getTableQuery() is deprecated in Filament 5,
     * and the resource query is the one every page, widget and export goes through.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Month-to-date net profit for one car, from a single fleet-wide `ReportService` call.
     *
     * The obvious implementation — `singleCarProfitability()` inside the column closure —
     * costs four queries *per rendered row* (measured: 40 for 10 rows). `carProfitability()`
     * returns every car in four queries total, so the whole page costs what one row used to.
     * Nothing is summed here; the figure comes from `ReportService` as the Phase 7 convention
     * requires.
     */
    public function monthToDateNetProfit(int $carId): float
    {
        if ($this->monthToDateNetProfit === null) {
            $month = CarbonImmutable::today();

            $this->monthToDateNetProfit = collect(
                app(ReportService::class)->carProfitability(
                    $month->startOfMonth(),
                    $month->endOfMonth(),
                ),
            )
                ->mapWithKeys(fn (array $row): array => [
                    (int) $row['car_id'] => (float) $row['net_profit'],
                ])
                ->all();
        }

        return $this->monthToDateNetProfit[$carId] ?? 0.0;
    }
}
