<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Branch;
use App\Models\Employee;
use App\Services\BranchContext;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * The employee record's only write path from the panel: a created employee is
 * numbered by the system, never by whoever is typing.
 *
 * The number is allocated through SequenceGenerator inside the same
 * transaction as the insert — that is the whole point of the generator: if the
 * insert rolls back, the number rolls back with it and no gap appears in the
 * sequence. Allocating outside a transaction throws (SequenceGuardTest).
 */
final class EmployeeService
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Employee
    {
        return DB::transaction(function () use ($attributes): Employee {
            $branchId = $this->resolveBranchId($attributes);

            $attributes['branch_id'] = $branchId;
            $attributes['employee_number'] = $this->sequences->next(
                'employee',
                $branchId,
                $branchId !== null ? Branch::find($branchId)?->code : null,
            );

            return Employee::create($attributes);
        });
    }

    /**
     * The branch the new employee belongs to — the same order
     * BelongsToBranch::resolveBranchId() uses (session context, then the
     * authenticated user's home branch, then the default branch), duplicated
     * here because the number must be minted before the insert, and the model
     * only resolves its branch on the creating event.
     *
     * @param array<string, mixed> $attributes
     */
    private function resolveBranchId(array $attributes): ?int
    {
        if (isset($attributes['branch_id'])) {
            return (int) $attributes['branch_id'];
        }

        if (config('branches.enabled', false)) {
            $context = app(BranchContext::class);

            if ($context->isResolved() && $context->activeBranchId() !== null) {
                return $context->activeBranchId();
            }
        }

        $user = auth()->user();

        if ($user !== null && isset($user->branch_id)) {
            return (int) $user->branch_id;
        }

        return Branch::defaultId();
    }
}
