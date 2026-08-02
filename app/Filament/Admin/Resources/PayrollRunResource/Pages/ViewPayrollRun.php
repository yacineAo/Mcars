<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayrollRunResource\Pages;

use App\Enums\PayrollStatus;
use App\Filament\Admin\Resources\PayrollRunResource;
use App\Filament\Admin\Resources\PayrollRunResource\RelationManagers\PayrollItemsRelationManager;
use App\Filament\Admin\Resources\PayrollRunResource\RelationManagers\PayrollTransactionsRelationManager;
use App\Models\PayrollRun;
use App\Services\Payment\PayrollService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The run's substance is its items — who is paid, what for, and what the
 * batch costs — which is exactly what the previous screen approved and paid
 * unseen. The totals here are derived from the items (PayrollService), never
 * stored, and the two relations below open only to reports.view_financials:
 * a run can be read for its status without exposing the month's numbers.
 */
class ViewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('payroll.sections.run'))
                    ->schema([
                        TextEntry::make('period_month')
                            ->label(__('payroll.fields.period_month'))
                            ->date('Y-m'),
                        TextEntry::make('branch.name')
                            ->label(__('payroll.fields.branch'))
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('payroll.fields.status'))
                            ->badge(),
                    ])
                    ->columns(3),

                Section::make(__('payroll.sections.approval_trail'))
                    ->schema([
                        TextEntry::make('approvedBy.name')
                            ->label(__('payroll.fields.approved_by'))
                            ->placeholder('—'),
                        TextEntry::make('approved_at')
                            ->label(__('payroll.fields.approved_at'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('paid_at')
                            ->label(__('payroll.fields.paid_at'))
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->visible(fn (PayrollRun $record): bool => $record->status !== PayrollStatus::Draft),

                Section::make(__('payroll.sections.totals'))
                    ->schema([
                        TextEntry::make('gross')
                            ->label(__('payroll.fields.gross'))
                            ->money('DZD')
                            ->state(fn (PayrollRun $record): string => app(PayrollService::class)
                                ->runTotals($record)['gross']
                                ->toDecimal()),
                        TextEntry::make('commissions')
                            ->label(__('payroll.fields.commissions'))
                            ->money('DZD')
                            ->state(fn (PayrollRun $record): string => app(PayrollService::class)
                                ->runTotals($record)['commissions']
                                ->toDecimal()),
                        TextEntry::make('advances')
                            ->label(__('payroll.fields.advances'))
                            ->money('DZD')
                            ->state(fn (PayrollRun $record): string => app(PayrollService::class)
                                ->runTotals($record)['advances']
                                ->toDecimal()),
                        TextEntry::make('net')
                            ->label(__('payroll.fields.total'))
                            ->money('DZD')
                            ->state(fn (PayrollRun $record): string => app(PayrollService::class)
                                ->runTotals($record)['net']
                                ->toDecimal()),
                    ])
                    ->columns(4),
            ]);
    }

    /**
     * The run's items and its postings share the view page, each gated on
     * reports.view_financials — the same separation the employee view keeps
     * between directory data and pay data.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('payroll.sections.items'), [
                PayrollItemsRelationManager::class,
                PayrollTransactionsRelationManager::class,
            ]),
        ];
    }
}
