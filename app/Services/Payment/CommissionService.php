<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\CommissionStatus;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\User;
use App\Support\Money;
use DomainException;

/**
 * Commissions are earned money: `amount` is always `basis_amount × rate / 100`,
 * computed here in integer minor units — never typed into the form, or the
 * record and its own basis/rate could disagree.
 *
 * Two things are owned by the payroll flow and never accepted from a payload:
 * the `status` (E59 posts when the run is approved) and the sweep stamp
 * `payroll_item_id` (the payroll run that paid it). Forging either would drop
 * a commission from the unpaid sweep queue without money moving — a crafted
 * `payroll_item_id` is stripped the same way a crafted `status` is.
 *
 * The employee is guarded twice: self-dealing is refused (a commission on your
 * own employee record is an agent paying themselves from the till, the same
 * guard as the advance), and the employee must belong to a branch the acting
 * user can reach — the form pins the options, this re-checks the payload.
 */
class CommissionService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes, int $userId): Commission
    {
        $this->assertCanCommissionFor((int) $attributes['employee_id'], $userId);

        // Every commission starts pending and unpaid: the status and the sweep
        // stamp belong to the payroll flow, never to the creator.
        $attributes['status'] = CommissionStatus::Pending->value;
        $attributes['payroll_item_id'] = null;
        $attributes['amount'] = $this->computeAmount($attributes['basis_amount'], $attributes['rate']);

        return Commission::create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Commission $commission, array $attributes, int $userId): Commission
    {
        if ($commission->payroll_item_id !== null || $commission->status === CommissionStatus::Paid) {
            throw new DomainException('A commission already swept into payroll cannot be edited.');
        }

        $this->assertCanCommissionFor((int) $attributes['employee_id'], $userId);

        // The status is never typed and the stamp is never moved: both belong
        // to the payroll flow, so a crafted payload cannot claim a payment the
        // ledger has not made. The amount is recomputed from basis × rate.
        $attributes['status'] = $commission->status->value;
        $attributes['payroll_item_id'] = $commission->payroll_item_id;
        $attributes['amount'] = $this->computeAmount($attributes['basis_amount'], $attributes['rate']);

        $commission->update($attributes);

        return $commission->fresh();
    }

    /** basis × rate / 100 — integer minor units, half-up, never a float. */
    private function computeAmount(string|int|float $basis, string|int|float $rate): string
    {
        return Money::of($basis)->times($rate)->dividedBy('100')->toDecimal();
    }

    private function assertCanCommissionFor(int $employeeId, int $userId): void
    {
        $employee = Employee::find($employeeId);

        if ($employee === null) {
            throw new DomainException('The chosen employee does not exist.');
        }

        if ($employee->user_id !== null && (int) $employee->user_id === $userId) {
            throw new DomainException('A commission on your own employee record cannot be created.');
        }

        // The form only offers reachable employees; a crafted payload re-checks
        // the same fact here. Money for another branch's payroll must not be
        // written from this one.
        $user = User::find($userId);

        if ($user === null || $employee->branch_id === null
            || ! in_array((int) $employee->branch_id, $user->accessibleBranchIds(), true)) {
            throw new DomainException('The chosen employee is outside the branches you can reach.');
        }
    }
}
