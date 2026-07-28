<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\ExpenseStatus;
use App\Models\AlertRule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use Carbon\CarbonImmutable;

/**
 * Office rent, internet, electricity — the bills that recur and get forgotten.
 *
 * Driven by expenses.next_occurrence_on on the recurring template row.
 */
final class RecurringExpenseDueDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::RecurringExpenseDue;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = Expense::query()
            ->with('category')
            ->where('is_recurring', true)
            ->whereNotNull('next_occurrence_on')
            ->whereDate('next_occurrence_on', '<=', $now->addDays($rule->days_before)->toDateString())
            // A rejected template should stop recurring; a paid one refers to the
            // instance already settled, not the next occurrence.
            ->whereNotIn('status', [ExpenseStatus::Rejected->value]);

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $expense) {
            $due = CarbonImmutable::parse((string) $expense->next_occurrence_on);

            /** @var ExpenseCategory|null $category */
            $category = $expense->category;

            yield new AlertSubject(
                subject: $expense,
                branchId: $expense->branch_id,
                payload: [
                    'category' => $category->name ?? '—',
                    'description' => $expense->description ?? '—',
                    'amount' => $expense->total_amount,
                    'due_date' => $due->translatedFormat('d/m/Y'),
                ],
            );
        }
    }
}
