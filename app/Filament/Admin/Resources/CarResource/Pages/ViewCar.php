<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use App\Models\Car;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCar extends ViewRecord
{
    protected static string $resource = CarResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $profitability = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('brand'),
                        TextEntry::make('model'),
                        TextEntry::make('trim'),
                        TextEntry::make('year'),
                        TextEntry::make('color'),
                        TextEntry::make('registration_number')
                            ->label('Plate'),
                        TextEntry::make('chassis_number')
                            ->label('VIN'),
                        TextEntry::make('engine_number'),
                    ])
                    ->columns(3),
                Section::make('Status & Specs')
                    ->schema([
                        TextEntry::make('status'),
                        TextEntry::make('ownership_type'),
                        TextEntry::make('body_type'),
                        TextEntry::make('transmission'),
                        TextEntry::make('fuel_type'),
                        TextEntry::make('seats'),
                        TextEntry::make('doors'),
                        TextEntry::make('odometer')
                            ->suffix(' km'),
                        TextEntry::make('category.name'),
                    ])
                    ->columns(3),
                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('daily_rate')
                            ->money('DZD'),
                        TextEntry::make('weekly_rate')
                            ->money('DZD'),
                        TextEntry::make('monthly_rate')
                            ->money('DZD'),
                        TextEntry::make('security_deposit_amount')
                            ->money('DZD'),
                        TextEntry::make('mileage_limit_per_day')
                            ->suffix(' km'),
                        TextEntry::make('extra_km_price')
                            ->money('DZD'),
                        TextEntry::make('late_hour_fee')
                            ->money('DZD'),
                    ])
                    ->columns(3),
                Section::make('Document Expiry')
                    ->schema([
                        TextEntry::make('insurance_expiry_date')
                            ->date(),
                        TextEntry::make('technical_inspection_expiry_date')
                            ->date(),
                        TextEntry::make('registration_expiry_date')
                            ->date(),
                        TextEntry::make('road_tax_expiry_date')
                            ->date(),
                    ])
                    ->columns(2),
                Section::make('Profitability (This Month)')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('profitability_revenue')
                            ->label('Revenue')
                            ->state(fn ($record) => $this->money($this->profitability($record), 'revenue')),
                        TextEntry::make('profitability_expenses')
                            ->label('Expenses')
                            ->state(fn ($record) => $this->money($this->profitability($record), 'expenses')),
                        TextEntry::make('profitability_net_profit')
                            ->label('Net Profit')
                            ->state(fn ($record) => $this->money($this->profitability($record), 'net_profit')),
                        TextEntry::make('profitability_rental_days')
                            ->label('Rental Days')
                            ->state(fn ($record) => ($this->profitability($record)['rental_days'] ?? 0).' days'),
                        TextEntry::make('profitability_utilisation')
                            ->label('Utilisation %')
                            ->state(fn ($record) => ($this->profitability($record)['utilisation_pct'] ?? 0).'%')
                            ->helperText('Share of calendar days in the month this car was on rent.'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Resolved once per request — five entries rendering five separate report queries
     * is five times the work for one row of numbers.
     *
     * @return array<string, mixed>
     */
    protected function profitability(Car $record): array
    {
        if ($this->profitability === null) {
            $now = CarbonImmutable::today();

            $this->profitability = app(ReportService::class)->singleCarProfitability(
                $record->id,
                $now->startOfMonth(),
                $now->endOfMonth(),
            ) ?? [];
        }

        return $this->profitability;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function money(array $data, string $key): string
    {
        return number_format((float) ($data[$key] ?? 0), 2).' DZD';
    }
}
