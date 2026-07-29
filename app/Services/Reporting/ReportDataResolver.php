<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ReportType;
use App\Models\Branch;
use App\Services\ReportService;

/**
 * Turns a ReportRequest into report data — the single mapping from report type to
 * ReportService call.
 *
 * Both the on-screen report page and ExportJob go through here. That is what makes
 * "the exported totals match the on-screen figures" a property of the code rather
 * than something to keep verifying by eye: there is one match expression, not two.
 *
 * It reads through ReportService and never aggregates anything itself — see
 * CLAUDE.md, "Every ledger aggregation goes through ReportService".
 */
class ReportDataResolver
{
    /** Cap on the top-customers list when no single customer is chosen. */
    private const CUSTOMER_LIST_LIMIT = 100;

    public function __construct(
        private readonly ReportService $reports,
    ) {}

    public function resolve(ReportRequest $request): mixed
    {
        $from = $request->from;
        $to = $request->to;
        $branchId = $request->branchId;
        $scopeId = $request->scopeId();

        return match ($request->type) {
            ReportType::ProfitAndLoss => $this->reports->profitAndLoss($from, $to, $branchId),
            ReportType::ExpenseBreakdown => $this->reports->expenseBreakdown($from, $to, $branchId),
            ReportType::CustomerReport => $scopeId !== null
                ? $this->reports->customerStatement($scopeId)
                : $this->reports->topCustomers($from, $to, $branchId, self::CUSTOMER_LIST_LIMIT),
            ReportType::FleetProfitability => $scopeId !== null
                ? $this->reports->singleCarProfitability($scopeId, $from, $to)
                : $this->reports->fleetProfitability($from, $to, $branchId),
            ReportType::CashFlow => $this->reports->cashFlow($from, $to, $branchId),
            ReportType::OwnerStatement => $this->reports->ownerStatement((int) $scopeId, $from, $to, $branchId),
            ReportType::ReceivablesAgeing => $this->reports->receivablesAgeing($branchId),
            ReportType::CashSessionAudit => $this->reports->cashSessionAudit($from, $to, $branchId),
        };
    }

    /**
     * Every report states its own scope, so a PDF that leaves the building cannot be
     * mistaken for a company-wide figure when it is one branch's.
     */
    public function branchName(?int $branchId): string
    {
        if ($branchId === null) {
            return __('reports.all_branches');
        }

        $branch = Branch::find($branchId);

        return $branch->name ?? "Branch #{$branchId}";
    }
}
