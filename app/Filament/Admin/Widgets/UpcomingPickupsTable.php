<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Admin\Widgets\Concerns\ScopesDashboardReports;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

/**
 * What is going out next (REQ-01) — operational, not financial.
 */
class UpcomingPickupsTable extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 3;

    protected static ?string $heading = 'Upcoming Pickups (Today & Tomorrow)';

    public static function canView(): bool
    {
        return Auth::check();
    }

    public function table(Table $table): Table
    {
        $today = CarbonImmutable::today();
        $branchId = $this->reportBranchId();

        return $table
            ->query(
                Booking::query()
                    ->with(['customer', 'car'])
                    ->when($branchId !== null, fn ($q) => $q->where('pickup_branch_id', $branchId))
                    ->where('status', BookingStatus::Confirmed->value)
                    ->whereBetween('pickup_at', [
                        $today->startOfDay(),
                        $today->addDay()->endOfDay(),
                    ]),
            )
            ->defaultSort('pickup_at')
            ->emptyStateHeading('No pickups scheduled')
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer
                        ? $record->customer->first_name.' '.$record->customer->last_name
                        : '-'),
                TextColumn::make('car.brand')
                    ->label('Car')
                    ->formatStateUsing(fn ($record) => $record->car
                        ? $record->car->brand.' '.$record->car->model
                        : '-'),
                TextColumn::make('pickup_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
