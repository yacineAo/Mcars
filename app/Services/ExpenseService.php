<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\ExpensePoster;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The expense lifecycle: record → submit → approve → pay.
 *
 * Every transition checks the current status, and `pay()` owns the transaction
 * boundary around the ledger posting — a Filament action must never do either
 * (ADR-013). Paying is the only step that touches the ledger; the guard against
 * paying twice is a server-side invariant, not a button's visibility.
 */
class ExpenseService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly ExpensePoster $poster,
        private readonly DatabaseManager $db,
    ) {}

    public function submitForApproval(Expense $expense, User $by): Expense
    {
        if ($expense->status !== ExpenseStatus::Draft) {
            throw new RuntimeException('Only a draft expense can be submitted for approval.');
        }

        $expense->status = ExpenseStatus::PendingApproval;
        $expense->save();

        return $expense->fresh();
    }

    public function approve(Expense $expense, User $by): Expense
    {
        if (! in_array($expense->status, [ExpenseStatus::Draft, ExpenseStatus::PendingApproval], true)) {
            throw new RuntimeException('Only a draft or pending expense can be approved.');
        }

        $expense->status = ExpenseStatus::Approved;
        $expense->approved_by_id = $by->id;
        $expense->approved_at = now();
        $expense->save();

        return $expense->fresh();
    }

    public function reject(Expense $expense, string $reason, User $by): Expense
    {
        if (! in_array($expense->status, [ExpenseStatus::Draft, ExpenseStatus::PendingApproval], true)) {
            throw new RuntimeException('Only a draft or pending expense can be rejected.');
        }

        $expense->status = ExpenseStatus::Rejected;
        $expense->rejection_reason = trim($reason);
        $expense->save();

        return $expense->fresh();
    }

    /**
     * Pay an approved expense: posts the E39 expense entry and marks it paid.
     *
     * The approval check and the posting run inside one transaction under a
     * row lock, so two payers (a double submit, a stale tab, a second caller)
     * cannot both pass the guard: exactly one posts, the other is refused.
     */
    public function pay(Expense $expense, PaymentMethod $method, FinancialAccount $account, User $by): Expense
    {
        return $this->db->transaction(function () use ($expense, $method, $account, $by): Expense {
            /** @var Expense|null $locked */
            $locked = Expense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('Expense no longer exists.');
            }

            if ($locked->status !== ExpenseStatus::Approved) {
                throw new RuntimeException('Only an approved expense can be paid.');
            }

            // The paying account and the expense must live in the same branch:
            // the E39 posting is stamped with the expense's branch, and a cross-
            // branch account would leave the ledger and the record pointing at
            // different ledgers (ADR-004).
            if ($account->branch_id !== $locked->branch_id) {
                throw new RuntimeException('The paying account must belong to the expense\'s branch.');
            }

            $locked->payment_method = $method;
            $locked->save();

            $draft = $this->poster->postImmediateExpense($locked, $account, $by->id);
            $transaction = $this->accounting->post($draft);

            $locked->status = ExpenseStatus::Paid;
            $locked->financial_account_id = $account->id;
            $locked->paid_at = now();
            $locked->transaction_id = $transaction->id;
            $locked->save();

            return $locked->fresh();
        });
    }
}
