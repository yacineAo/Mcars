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

    /**
     * Whether the report covers a date range.
     *
     * Receivables ageing is a position at a moment, not a flow over a period —
     * its buckets are measured against today whatever dates you hand it. Showing
     * it under a "1–31 July" heading would claim a scope it does not have.
     */
    public function isPeriodic(): bool
    {
        return $this !== self::ReceivablesAgeing;
    }

    /**
     * The optional entity the report can be narrowed to, if any.
     *
     * Returning the parameter key rather than a boolean keeps the form, the
     * resolver and the export path reading the same word for the same thing.
     */
    public function scopeField(): ?string
    {
        return match ($this) {
            self::CustomerReport => 'customer_id',
            self::FleetProfitability => 'car_id',
            self::OwnerStatement => 'car_owner_id',
            default => null,
        };
    }

    /**
     * Whether the entity above is mandatory. An owner statement without an owner
     * is not a narrower report, it is no report at all.
     */
    public function requiresScope(): bool
    {
        return $this === self::OwnerStatement;
    }
}
