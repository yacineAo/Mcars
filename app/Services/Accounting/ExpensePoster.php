<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\FinancialAccount;
use DateTimeImmutable;

class ExpensePoster
{
    public function postImmediateExpense(Expense $expense, FinancialAccount $account, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $expense->category->ledger_account_id,
            creditAccountId: $account->ledger_account_id,
            amount: $expense->total_amount,
            type: TransactionType::Expense,
            occurredOn: new DateTimeImmutable($expense->incurred_on->format('Y-m-d')),
            description: $expense->description ?? 'Expense: '.$expense->reference,
            branchId: $expense->branch_id,
            carId: $expense->car_id,
            expenseCategoryId: $expense->expense_category_id,
            sourceType: 'expense',
            sourceId: $expense->id,
            createdById: $userId,
            paymentMethod: $expense->payment_method,
        );
    }

    public function postAccruedExpense(Expense $expense, int $userId): TransactionDraft
    {
        $payableId = ChartOfAccount::where('code', '2210')->valueOrFail('id'); // AP–Suppliers

        return new TransactionDraft(
            debitAccountId: $expense->category->ledger_account_id,
            creditAccountId: $payableId,
            amount: $expense->total_amount,
            type: TransactionType::Expense,
            occurredOn: new DateTimeImmutable($expense->incurred_on->format('Y-m-d')),
            description: $expense->description ?? 'Expense (accrued): '.$expense->reference,
            branchId: $expense->branch_id,
            carId: $expense->car_id,
            expenseCategoryId: $expense->expense_category_id,
            sourceType: 'expense',
            sourceId: $expense->id,
            createdById: $userId,
        );
    }
}
