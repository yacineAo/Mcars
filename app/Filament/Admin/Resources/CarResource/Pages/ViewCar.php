<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\CarResource\RelationManagers\BlocksRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\BookingsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\FinesRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\OwnerInstallmentsRelationManager;
use App\Models\Car;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Throwable;

class ViewCar extends ViewRecord
{
    protected static string $resource = CarResource::class;

    /**
     * The profitability period. REQ-02 asks for *total* profit, expenses and rental days,
     * so lifetime is the default; month and year are the two comparisons a manager actually
     * makes. Held as Livewire state so the picker survives a re-render.
     *
     * `#[Locked]` because these three reach `CarbonImmutable::parse()`. Without it they are
     * client-writable, and `parse()` throws `InvalidFormatException` on anything that is not
     * a date — a crafted Livewire payload would be an unhandled 500 rather than a validation
     * error. The header action still sets them server-side, which `#[Locked]` permits.
     */
    #[Locked]
    public string $profitabilityPeriod = self::PERIOD_LIFETIME;

    #[Locked]
    public ?string $profitabilityFrom = null;

    #[Locked]
    public ?string $profitabilityTo = null;

    private const string PERIOD_MONTH = 'month';

    private const string PERIOD_YEAR = 'year';

    private const string PERIOD_LIFETIME = 'lifetime';

    private const string PERIOD_CUSTOM = 'custom';

    /** @var array<string, mixed>|null */
    protected ?array $profitability = null;

    /**
     * Read-only history lives on the view page. Filament renders getRelations() on both the
     * view and the edit page, so without this override the writable managers would appear
     * here too — which is how a receptionist could create an ownership agreement from a
     * page they opened to read.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Bookings'), [
                BookingsRelationManager::class,
                ContractsRelationManager::class,
                FinesRelationManager::class,
                BlocksRelationManager::class,
            ]),
            RelationGroup::make(__('Owner'), [
                OwnerInstallmentsRelationManager::class,
            ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('profitability_period')
                ->label(__('Profitability period'))
                ->icon('heroicon-o-calendar')
                ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                ->fillForm(fn (): array => [
                    'period' => $this->profitabilityPeriod,
                    'from' => $this->profitabilityFrom,
                    'to' => $this->profitabilityTo,
                ])
                ->form([
                    Select::make('period')
                        ->label('Period')
                        ->options([
                            self::PERIOD_MONTH => __('This month'),
                            self::PERIOD_YEAR => __('This year'),
                            self::PERIOD_LIFETIME => __('Lifetime'),
                            self::PERIOD_CUSTOM => __('Custom range'),
                        ])
                        ->default(self::PERIOD_LIFETIME)
                        ->live()
                        ->required()
                        // Belt and braces alongside #[Locked]: the value reaches a date
                        // calculation, so it is validated against the known keys here too.
                        ->in([self::PERIOD_MONTH, self::PERIOD_YEAR, self::PERIOD_LIFETIME, self::PERIOD_CUSTOM]),
                    DatePicker::make('from')
                        ->label('From')
                        ->visible(fn (callable $get): bool => $get('period') === self::PERIOD_CUSTOM)
                        ->required(fn (callable $get): bool => $get('period') === self::PERIOD_CUSTOM),
                    DatePicker::make('to')
                        ->label('To')
                        ->visible(fn (callable $get): bool => $get('period') === self::PERIOD_CUSTOM)
                        ->required(fn (callable $get): bool => $get('period') === self::PERIOD_CUSTOM)
                        ->afterOrEqual('from'),
                ])
                ->action(function (array $data): void {
                    $this->profitabilityPeriod = (string) $data['period'];
                    $this->profitabilityFrom = $data['from'] ?? null;
                    $this->profitabilityTo = $data['to'] ?? null;

                    // Drop the memo so the next render re-queries for the new period.
                    $this->profitability = null;
                }),
        ];
    }

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
                Section::make('Photos')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('gallery')
                            ->collection('gallery')
                            ->label('Gallery')
                            ->placeholder(__('No photos')),
                        SpatieMediaLibraryImageEntry::make('damage')
                            ->collection('damage')
                            ->label('Damage')
                            ->placeholder(__('No damage photos')),
                    ])
                    ->columns(1)
                    ->collapsible(),
                Section::make('Status & Specs')
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
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
                // Days remaining, coloured, not just a date — this section is the reason a
                // maintenance officer opens the page, and "expires in 4 days" is the fact
                // they need, not "2026-08-02".
                Section::make('Document Expiry')
                    ->schema([
                        $this->expiryEntry('insurance_expiry_date', __('enums.car_document_type.insurance')),
                        $this->expiryEntry('technical_inspection_expiry_date', __('enums.car_document_type.technical_inspection')),
                        $this->expiryEntry('registration_expiry_date', __('enums.car_document_type.registration_card')),
                        $this->expiryEntry('road_tax_expiry_date', __('enums.car_document_type.road_tax_vignette')),
                    ])
                    ->columns(2),
                Section::make('Profitability')
                    ->description(fn (): string => $this->periodLabel())
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('profitability_revenue')
                            ->label('Revenue')
                            ->state(fn (Car $record): string => $this->money($this->profitability($record), 'revenue')),
                        TextEntry::make('profitability_expenses')
                            ->label('Expenses')
                            ->state(fn (Car $record): string => $this->money($this->profitability($record), 'expenses')),
                        TextEntry::make('profitability_net_profit')
                            ->label('Net Profit')
                            ->state(fn (Car $record): string => $this->money($this->profitability($record), 'net_profit')),
                        TextEntry::make('profitability_rental_days')
                            ->label('Rental Days')
                            ->state(fn (Car $record): string => __(':count days', ['count' => $this->profitability($record)['rental_days'] ?? 0])),
                        TextEntry::make('profitability_utilisation')
                            ->label('Utilisation %')
                            ->state(fn (Car $record): string => ($this->profitability($record)['utilisation_pct'] ?? 0).'%')
                            ->helperText(__('Share of calendar days in the period this car was on rent.')),
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
            [$from, $to] = $this->resolvePeriod($record);

            $this->profitability = app(ReportService::class)->singleCarProfitability(
                $record->id,
                $from,
                $to,
            ) ?? [];
        }

        return $this->profitability;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function resolvePeriod(Car $record): array
    {
        $today = CarbonImmutable::today();

        return match ($this->profitabilityPeriod) {
            self::PERIOD_MONTH => [$today->startOfMonth(), $today->endOfMonth()],
            self::PERIOD_YEAR => [$today->startOfYear(), $today->endOfYear()],
            self::PERIOD_CUSTOM => [
                $this->parseOr($this->profitabilityFrom, $today->startOfMonth())->startOfDay(),
                $this->parseOr($this->profitabilityTo, $today->endOfMonth())->endOfDay(),
            ],
            // Lifetime starts the day the car entered the fleet, not an arbitrary epoch —
            // utilisation divides by calendar days in the period, so starting at 1970 would
            // report a utilisation of nearly zero for every car.
            default => [
                CarbonImmutable::parse(
                    ($record->purchase_date ?? $record->created_at ?? $today)->format('Y-m-d'),
                )->startOfDay(),
                $today->endOfDay(),
            ],
        };
    }

    /**
     * `CarbonImmutable::parse()` throws on anything that is not a date. The picker only ever
     * submits one, but a report period is not worth a 500 if that ever stops being true.
     */
    private function parseOr(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === null) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return $fallback;
        }
    }

    protected function periodLabel(): string
    {
        $record = $this->getRecord();
        assert($record instanceof Car);

        [$from, $to] = $this->resolvePeriod($record);

        return match ($this->profitabilityPeriod) {
            self::PERIOD_MONTH => __('This month'),
            self::PERIOD_YEAR => __('This year'),
            self::PERIOD_CUSTOM => $from->format('d/m/Y').' → '.$to->format('d/m/Y'),
            default => __('Lifetime — since :date', ['date' => $from->format('d/m/Y')]),
        };
    }

    protected function expiryEntry(string $field, string $label): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->placeholder(__('Not recorded'))
            ->badge()
            ->color(fn (?string $state): string => match (true) {
                $state === null => 'gray',
                self::daysUntil($state) < 0 => 'danger',
                self::daysUntil($state) <= 30 => 'warning',
                default => 'success',
            })
            ->formatStateUsing(function (?string $state): string {
                if ($state === null) {
                    return __('Not recorded');
                }

                $date = CarbonImmutable::parse($state)->startOfDay();
                $days = self::daysUntil($state);

                return match (true) {
                    $days < 0 => __(':date — expired :days days ago', ['date' => $date->format('d/m/Y'), 'days' => abs($days)]),
                    $days === 0 => __(':date — expires today', ['date' => $date->format('d/m/Y')]),
                    default => __(':date — :days days left', ['date' => $date->format('d/m/Y'), 'days' => $days]),
                };
            });
    }

    /**
     * Days from today until `$state`: positive in the future, negative once past.
     *
     * The operand order matters and is the whole reason this is a named method rather than
     * inline in two closures. Carbon 3's `diffInDays()` is *signed*, so
     * `parse($state)->diffInDays(today())` returns −337 for a date 337 days away, and a
     * `<= 30` test on that is true for every future date — which made the "success" colour
     * unreachable and painted every valid document amber.
     *
     * Public so the sign convention itself can be asserted directly; that is the part that
     * broke, and it is not reachable through a rendered badge colour.
     */
    public static function daysUntil(string $state): int
    {
        return (int) CarbonImmutable::today()->diffInDays(
            CarbonImmutable::parse($state)->startOfDay(),
            absolute: false,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function money(array $data, string $key): string
    {
        return number_format((float) ($data[$key] ?? 0), 2).' DZD';
    }
}
