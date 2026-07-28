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
 * The day's operational worklist (REQ-01) — visible to everyone who can log in,
 * including receptionists.
 */
class DueReturnsTodayTable extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Due Returns Today';

    public static function canView(): bool
    {
        return Auth::check();
    }

    public function table(Table $table): Table
    {
        $branchId = $this->reportBranchId();

        return $table
            ->query(
                Booking::query()
                    ->with(['customer', 'car'])
                    ->when($branchId !== null, fn ($q) => $q->where('pickup_branch_id', $branchId))
                    ->whereIn('status', [BookingStatus::Active->value, BookingStatus::Overdue->value])
                    ->whereDate('expected_return_at', CarbonImmutable::today()->toDateString()),
            )
            ->defaultSort('expected_return_at')
            ->emptyStateHeading('No returns due today')
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer
                        ? $record->customer->first_name.' '.$record->customer->last_name
                        : '-'),
                TextColumn::make('car.registration_number')
                    ->label('Car Plate'),
                TextColumn::make('expected_return_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
            ]);
    }
}
