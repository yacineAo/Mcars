<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeAdvanceResource\Pages;

use App\Filament\Admin\Resources\EmployeeAdvanceResource;
use App\Filament\Admin\Resources\PayrollRunResource;
use App\Models\EmployeeAdvance;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewEmployeeAdvance extends ViewRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Advance'))
                    ->schema([
                        TextEntry::make('employee.first_name')
                            ->label(__('employee_advances.fields.employee'))
                            ->formatStateUsing(fn (EmployeeAdvance $record): string => trim($record->employee->first_name.' '.$record->employee->last_name)),
                        TextEntry::make('amount')
                            ->label(__('employee_advances.fields.amount'))
                            ->money('DZD')
                            ->weight('bold'),
                        TextEntry::make('advanced_on')
                            ->label(__('employee_advances.fields.advanced_on'))
                            ->date(),
                        TextEntry::make('status')
                            ->label(__('employee_advances.fields.status'))
                            ->badge(),
                        TextEntry::make('recoveredInPayrollItem.payrollRun.period_month')
                            ->label(__('employee_advances.columns.recovered_in'))
                            ->date('Y-m')
                            ->placeholder('—')
                            ->url(fn (EmployeeAdvance $record): ?string => $record->recoveredInPayrollItem?->payrollRun !== null && PayrollRunResource::canAccess()
                                ? PayrollRunResource::getUrl('view', ['record' => $record->recoveredInPayrollItem->payrollRun])
                                : null),
                    ])
                    ->columns(4),
                Section::make(__('Reason'))
                    ->schema([
                        TextEntry::make('reason')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
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
