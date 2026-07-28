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
 * Cars that should already be back (REQ-01) — operational, not financial.
 */
class OverdueReturnsTable extends BaseWidget
{
    use ScopesDashboardReports;

    protected static ?int $sort = 4;

    protected static ?string $heading = 'Overdue Returns';

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
                    ->where('expected_return_at', '<', CarbonImmutable::now()),
            )
            ->defaultSort('expected_return_at')
            ->emptyStateHeading('Nothing overdue')
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
                    ->sortable()
                    ->label('Due At'),
                TextColumn::make('status')
                    ->badge(),
            ]);
    }
}
