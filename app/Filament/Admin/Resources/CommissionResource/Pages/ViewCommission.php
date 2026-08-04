<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionResource\Pages;

use App\Filament\Admin\Resources\CommissionResource;
use App\Filament\Admin\Resources\PayrollRunResource;
use App\Models\Commission;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewCommission extends ViewRecord
{
    protected static string $resource = CommissionResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Commission'))
                    ->schema([
                        TextEntry::make('employee.first_name')
                            ->label(__('commissions.fields.employee'))
                            ->formatStateUsing(fn (Commission $record): string => trim($record->employee->first_name.' '.$record->employee->last_name)),
                        TextEntry::make('booking.reference')
                            ->label(__('commissions.fields.booking'))
                            ->placeholder('—'),
                        TextEntry::make('earned_on')
                            ->label(__('commissions.fields.earned_on'))
                            ->date(),
                        TextEntry::make('status')
                            ->label(__('commissions.columns.status'))
                            ->badge(),
                    ])
                    ->columns(4),
                Section::make(__('Money'))
                    ->schema([
                        TextEntry::make('basis_amount')
                            ->label(__('commissions.fields.basis_amount'))
                            ->money('DZD'),
                        TextEntry::make('rate')
                            ->label(__('commissions.fields.rate'))
                            ->suffix('%'),
                        TextEntry::make('amount')
                            ->label(__('commissions.columns.amount'))
                            ->money('DZD')
                            ->weight('bold'),
                        TextEntry::make('payrollItem.payrollRun.period_month')
                            ->label(__('commissions.columns.swept_in'))
                            ->date('Y-m')
                            ->placeholder('—')
                            ->url(fn (Commission $record): ?string => $record->payrollItem?->payrollRun !== null && PayrollRunResource::canAccess()
                                ? PayrollRunResource::getUrl('view', ['record' => $record->payrollItem->payrollRun])
                                : null),
                    ])
                    ->columns(4),
                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
