<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use DateTimeImmutable;

/**
 * E70/E71 — money moving between the business and its owner(s). This is
 * equity, never revenue or an expense: Dr/Cr always runs against 3000 Owner
 * Capital or 3200 Drawings, not a P&L account.
 */
class CapitalPoster
{
    /** E70: capital the owner puts into the business. */
    public function postInjection(FinancialAccount $account, string $amount, string $occurredOn, int $userId, ?string $description = null): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $account->ledger_account_id,
            creditAccountId: $this->resolveId('3000'), // Owner Capital
            amount: $amount,
            type: TransactionType::Capital,
            occurredOn: new DateTimeImmutable($occurredOn),
            description: $description ?? 'Capital injection — '.$account->name,
            branchId: $account->branch_id,
            createdById: $userId,
            sourceType: 'financial_account',
            sourceId: $account->id,
        );
    }

    /** E71: money the owner takes out of the business. */
    public function postDrawing(FinancialAccount $account, string $amount, string $occurredOn, int $userId, ?string $description = null): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('3200'), // Drawings
            creditAccountId: $account->ledger_account_id,
            amount: $amount,
            type: TransactionType::Drawings,
            occurredOn: new DateTimeImmutable($occurredOn),
            description: $description ?? 'Owner drawing — '.$account->name,
            branchId: $account->branch_id,
            createdById: $userId,
            sourceType: 'financial_account',
            sourceId: $account->id,
        );
    }

    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }
}
