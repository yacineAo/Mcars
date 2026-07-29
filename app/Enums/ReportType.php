<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ReportType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case ProfitAndLoss = 'profit_and_loss';
    case ExpenseBreakdown = 'expense_breakdown';
    case CustomerReport = 'customer_report';
    case FleetProfitability = 'fleet_profitability';
    case CashFlow = 'cash_flow';
    case OwnerStatement = 'owner_statement';
    case ReceivablesAgeing = 'receivables_ageing';
    case CashSessionAudit = 'cash_session_audit';

    public function getColor(): string
    {
        return match ($this) {
            self::ProfitAndLoss => 'primary',
            self::ExpenseBreakdown => 'warning',
            self::CustomerReport => 'info',
            self::FleetProfitability => 'success',
            self::CashFlow => 'success',
            self::OwnerStatement => 'gray',
            self::ReceivablesAgeing => 'danger',
            self::CashSessionAudit => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ProfitAndLoss => 'heroicon-o-chart-bar',
            self::ExpenseBreakdown => 'heroicon-o-arrow-trending-down',
            self::CustomerReport => 'heroicon-o-users',
            self::FleetProfitability => 'heroicon-o-truck',
            self::CashFlow => 'heroicon-o-banknotes',
            self::OwnerStatement => 'heroicon-o-user-circle',
            self::ReceivablesAgeing => 'heroicon-o-clock',
            self::CashSessionAudit => 'heroicon-o-receipt-percent',
        };
    }
}
