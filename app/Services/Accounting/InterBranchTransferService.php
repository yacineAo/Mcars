<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use DateTimeImmutable;
use RuntimeException;

/**
 * Inter-branch money transfer.
 *
 * Moves funds between two branches' cash/register accounts through the
 * inter-branch clearing account (2600). Each transfer produces two ledger
 * rows that share a group_uuid in their meta field:
 *
 *   Branch A (outgoing): Debit account 2600 → Credit source account
 *   Branch B (incoming): Debit destination account → Credit account 2600
 *
 * Company-wide reports exclude account 2600 so the transfer nets to zero.
 * Per-branch reports include every row posted to that branch.
 */
class InterBranchTransferService
{
    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    /**
     * Transfer funds from one branch's financial account to another's.
     *
     * @return array{outgoing: Transaction, incoming: Transaction}
     */
    public function transfer(
        FinancialAccount $fromAccount,
        FinancialAccount $toAccount,
        string $amount,
        string $description,
        User $by,
    ): array {
        if ($fromAccount->branch_id === $toAccount->branch_id) {
            throw new RuntimeException(
                'Inter-branch transfer requires two different branches.',
            );
        }

        $clearingAccountId = $this->resolveClearingAccountId();
        $occurredOn = new DateTimeImmutable;

        $outgoingDraft = new TransactionDraft(
            debitAccountId: $clearingAccountId,
            creditAccountId: $fromAccount->ledger_account_id,
            amount: $amount,
            type: TransactionType::CashTransfer,
            occurredOn: $occurredOn,
            description: "{$description} (outgoing)",
            branchId: $fromAccount->branch_id,
            createdById: $by->id,
            meta: [
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'from_branch_id' => $fromAccount->branch_id,
                'to_branch_id' => $toAccount->branch_id,
            ],
        );

        $incomingDraft = new TransactionDraft(
            debitAccountId: $toAccount->ledger_account_id,
            creditAccountId: $clearingAccountId,
            amount: $amount,
            type: TransactionType::CashTransfer,
            occurredOn: $occurredOn,
            description: "{$description} (incoming)",
            branchId: $toAccount->branch_id,
            createdById: $by->id,
            meta: [
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'from_branch_id' => $fromAccount->branch_id,
                'to_branch_id' => $toAccount->branch_id,
            ],
        );

        $transactions = $this->accounting->postMany($outgoingDraft, $incomingDraft);

        return [
            'outgoing' => $transactions->first(),
            'incoming' => $transactions->last(),
        ];
    }

    private function resolveClearingAccountId(): int
    {
        $account = ChartOfAccount::where('code', '2600')->first();

        if ($account === null) {
            throw new RuntimeException(
                'Inter-branch clearing account (2600) not found in the chart of accounts.',
            );
        }

        return $account->id;
    }
}
