<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The one screen the manager opens to know how the business is doing (REQ-01, REQ-18).
 *
 * Filters live here and reach the widgets through ScopesDashboardReports. The branch
 * selector is only rendered for users who may already see every branch — and the trait
 * re-checks that server-side, because a rendered form is not an authorisation boundary.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Dashboard';

    public function filtersForm(Schema $schema): Schema
    {
        $today = CarbonImmutable::today();

        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('branch_id')
                        ->label('Branch')
                        ->placeholder('All branches')
                        ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
                    DatePicker::make('from')
                        ->label('From')
                        ->default($today->startOfMonth())
                        ->maxDate(fn (callable $get) => $get('to')),
                    DatePicker::make('to')
                        ->label('To')
                        ->default($today->endOfMonth())
                        ->minDate(fn (callable $get) => $get('from')),
                ])
                ->columns(3),
        ]);
    }
}
