<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\PayrollRun;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class PayrollPoster
{
    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }

    /**
     * E57 + E59: Payroll approved — gross + commissions
     *
     * @return list<TransactionDraft>
     */
    public function postPayrollApproved(PayrollRun $run, int $userId): array
    {
        $drafts = [];
        $occurredOn = new DateTimeImmutable($run->period_month->format('Y-m-d'));

        foreach ($run->items as $item) {
            if ((float) $item->gross_amount > 0) {
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId('5080'),
                    creditAccountId: $this->resolveId('2300'),
                    amount: (string) $item->gross_amount,
                    type: TransactionType::Payroll,
                    occurredOn: $occurredOn,
                    description: 'Salary — '.$item->employee?->first_name,
                    branchId: $run->branch_id,
                    createdById: $userId,
                    employeeId: $item->employee_id,
                    sourceType: 'payroll_run',
                    sourceId: $run->id,
                    meta: ['payroll_run_id' => (string) $run->id, 'payroll_item_id' => (string) $item->id],
                );
            }

            if ((float) $item->social_contributions > 0) {
                // E58: Employer social contributions
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId('5085'),
                    creditAccountId: $this->resolveId('2310'),
                    amount: (string) $item->social_contributions,
                    type: TransactionType::Payroll,
                    occurredOn: $occurredOn,
                    description: 'Social contributions — '.$item->employee?->first_name,
                    branchId: $run->branch_id,
                    createdById: $userId,
                    employeeId: $item->employee_id,
                    sourceType: 'payroll_run',
                    sourceId: $run->id,
                    meta: ['payroll_run_id' => (string) $run->id],
                );
            }

            if ((float) $item->commissions_amount > 0) {
                // E59: Commissions accrued
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId('5090'),
                    creditAccountId: $this->resolveId('2300'),
                    amount: (string) $item->commissions_amount,
                    type: TransactionType::Commission,
                    occurredOn: $occurredOn,
                    description: 'Commission — '.$item->employee?->first_name,
                    branchId: $run->branch_id,
                    createdById: $userId,
                    employeeId: $item->employee_id,
                    sourceType: 'payroll_run',
                    sourceId: $run->id,
                    meta: ['payroll_run_id' => (string) $run->id],
                );
            }

            // E62: Advance recovery (reduces payable, clears the asset)
            if ((float) $item->advances_deducted > 0) {
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId('2300'),
                    creditAccountId: $this->resolveId('1130'),
                    amount: (string) $item->advances_deducted,
                    type: TransactionType::AdvanceRecovery,
                    occurredOn: $occurredOn,
                    description: 'Advance recovery — '.$item->employee?->first_name,
                    branchId: $run->branch_id,
                    createdById: $userId,
                    employeeId: $item->employee_id,
                    sourceType: 'payroll_run',
                    sourceId: $run->id,
                    meta: ['payroll_run_id' => (string) $run->id],
                );
            }
        }

        return $drafts;
    }

    /**
     * E60: Net salary paid
     *
     * @return list<TransactionDraft>
     */
    public function postPayrollPaid(PayrollRun $run, int $userId): array
    {
        $drafts = [];
        $occurredOn = new DateTimeImmutable('now');

        foreach ($run->items as $item) {
            if ((float) $item->net_amount > 0) {
                $drafts[] = new TransactionDraft(
                    debitAccountId: $this->resolveId('2300'),
                    creditAccountId: $this->resolveId('1010'),
                    amount: (string) $item->net_amount,
                    type: TransactionType::PayrollPayment,
                    occurredOn: $occurredOn,
                    description: 'Net salary paid — '.$item->employee?->first_name,
                    branchId: $run->branch_id,
                    createdById: $userId,
                    employeeId: $item->employee_id,
                    cashSessionId: null,
                    sourceType: 'payroll_run',
                    sourceId: $run->id,
                    meta: ['payroll_run_id' => (string) $run->id],
                );
            }
        }

        return $drafts;
    }

    /** E61: Employee advance given */
    public function postAdvance(string $amount, int $employeeId, ?int $branchId, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('1130'),
            creditAccountId: $this->resolveId('1010'),
            amount: $amount,
            type: TransactionType::Advance,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Employee advance',
            branchId: $branchId,
            createdById: $userId,
            employeeId: $employeeId,
        );
    }
}
