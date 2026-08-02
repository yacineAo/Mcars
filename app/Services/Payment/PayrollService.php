<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AdvanceStatus;
use App\Enums\CommissionStatus;
use App\Enums\EmployeeStatus;
use App\Enums\PayrollStatus;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Generates a month's payroll for a branch.
 *
 * A run is generated, not typed: creating it gathers every active employee's
 * base salary, their unrecovered advances and their unpaid commissions into
 * items, all in one transaction. The items are evidence — the run's totals are
 * their sum, never stored.
 *
 * Sweep semantics: a commission is "unpaid" while `payroll_item_id` is null,
 * and an advance is unrecovered while `recovered_in_payroll_item_id` is null.
 * Generation claims both: the moment an amount lands in a run it can never be
 * gathered again, so a second run for the same month cannot pay it twice. The
 * single-shot nature is the advance's own invariant (AdvanceStatus).
 */
final class PayrollService
{
    /**
     * Create the run for a branch and period, and fill it with items.
     *
     * @return PayrollRun the new run, still draft — nothing is posted yet
     *
     * @throws DomainException when the branch already has a live run for the
     *                         period
     */
    public function generate(int $branchId, string $periodMonth): PayrollRun
    {
        return DB::transaction(function () use ($branchId, $periodMonth): PayrollRun {
            // The form picks a month ('2026-07'); a run is always the first of
            // that month — the column is a date, and the poster keys its
            // postings off it.
            $period = CarbonImmutable::parse($periodMonth)->startOfMonth();

            if (PayrollRun::query()
                ->where('branch_id', $branchId)
                ->where('period_month', $period->format('Y-m-d'))
                ->where('status', '!=', PayrollStatus::Cancelled->value)
                ->exists()) {
                throw new DomainException('This branch already has a payroll run for that month.');
            }

            $employees = Employee::query()
                ->where('branch_id', $branchId)
                ->where('status', EmployeeStatus::Active->value)
                ->orderBy('first_name')
                ->get();

            $advances = EmployeeAdvance::query()
                ->where('branch_id', $branchId)
                ->where('status', AdvanceStatus::Outstanding->value)
                ->whereNull('recovered_in_payroll_item_id')
                ->get();

            $commissions = Commission::query()
                ->where('branch_id', $branchId)
                ->whereNull('payroll_item_id')
                ->where('status', '!=', CommissionStatus::Cancelled->value)
                ->get();

            $run = PayrollRun::create([
                'branch_id' => $branchId,
                'period_month' => $period->format('Y-m-d'),
                'status' => PayrollStatus::Draft,
            ]);

            foreach ($employees as $employee) {
                $this->itemFor($run, $employee, $advances, $commissions);
            }

            return $run;
        });
    }

    /**
     * Build one employee's item and claim its unpaid amounts.
     *
     * @param iterable<EmployeeAdvance> $advances
     * @param iterable<Commission> $commissions
     */
    private function itemFor(
        PayrollRun $run,
        Employee $employee,
        iterable $advances,
        iterable $commissions,
    ): void {
        $commissionTotal = Money::of('0');
        foreach ($commissions as $commission) {
            if ($commission->employee_id === $employee->id) {
                $commissionTotal = $commissionTotal->plus(Money::of((string) $commission->amount));
            }
        }

        $advanceTotal = Money::of('0');
        foreach ($advances as $advance) {
            if ($advance->employee_id === $employee->id) {
                $advanceTotal = $advanceTotal->plus(Money::of((string) $advance->amount));
            }
        }

        $base = Money::of((string) $employee->base_salary);
        $net = $base->plus($commissionTotal)->minus($advanceTotal);

        if ($base->isZero() && $commissionTotal->isZero() && $advanceTotal->isZero()) {
            return;
        }

        $item = new PayrollItem([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base_salary' => $base->toDecimal(),
            'commissions_amount' => $commissionTotal->toDecimal(),
            'bonuses_amount' => '0.00',
            'overtime_amount' => '0.00',
            'advances_deducted' => $advanceTotal->toDecimal(),
            'absences_deduction' => '0.00',
            'social_contributions' => '0.00',
            'other_deductions' => '0.00',
            'gross_amount' => $base->toDecimal(),
            'net_amount' => $net->toDecimal(),
            'status' => 'pending',
        ]);
        $item->save();

        // Claim the swept amounts on the item itself: while `payroll_item_id`
        // is null the commission is still unpaid, and while
        // `recovered_in_payroll_item_id` is null the advance is still
        // unrecovered — either would be gathered again by the next run.
        $commissionIds = collect($commissions)
            ->where('employee_id', $employee->id)
            ->pluck('id');
        if ($commissionIds->isNotEmpty()) {
            Commission::whereIn('id', $commissionIds)->update(['payroll_item_id' => $item->id]);
        }

        $advanceIds = collect($advances)
            ->where('employee_id', $employee->id)
            ->pluck('id');
        if ($advanceIds->isNotEmpty()) {
            EmployeeAdvance::whereIn('id', $advanceIds)->update(['recovered_in_payroll_item_id' => $item->id]);
        }
    }

    /**
     * The run's derived totals — the sum of its items, computed here so no
     * screen ever sums money itself (docs/05-accounting-model.md).
     *
     * @return array{gross: Money, commissions: Money, advances: Money, net: Money}
     */
    public function runTotals(PayrollRun $run): array
    {
        $items = $run->items()->get();
        $sum = function (string $column) use ($items): Money {
            $total = Money::of('0');

            foreach ($items as $item) {
                $total = $total->plus(Money::of((string) $item->getAttribute($column)));
            }

            return $total;
        };

        return [
            'gross' => $sum('gross_amount'),
            'commissions' => $sum('commissions_amount'),
            'advances' => $sum('advances_deducted'),
            'net' => $sum('net_amount'),
        ];
    }

    /**
     * What the run pays out — the index column and the view summary.
     */
    public function totalNetFor(PayrollRun $run): Money
    {
        return $this->runTotals($run)['net'];
    }

    /**
     * Release a draft item's claimed amounts back to the sweep queue. Deleting
     * an item while the run is still draft is a correction, and must not bury
     * the commission or the advance with it.
     */
    public function unsweep(PayrollItem $item): void
    {
        DB::transaction(function () use ($item): void {
            Commission::where('payroll_item_id', $item->id)->update(['payroll_item_id' => null]);
            EmployeeAdvance::where('recovered_in_payroll_item_id', $item->id)
                ->update(['recovered_in_payroll_item_id' => null]);
            $item->delete();
        });
    }
}
