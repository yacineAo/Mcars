<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
use DateTimeImmutable;

class CashSessionPoster
{
    public function postOpeningFloat(CashSession $session, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $session->financialAccount->ledger_account_id,
            creditAccountId: $this->resolveId('1015'), // Safe / Reserve
            amount: $session->opening_float,
            type: TransactionType::OpeningFloat,
            occurredOn: new DateTimeImmutable($session->opened_at->format('Y-m-d')),
            description: 'Opening float for session #'.$session->id,
            branchId: $session->branch_id,
            createdById: $userId,
            cashSessionId: $session->id,
        );
    }

    public function postCashOver(CashSession $session, string $varianceAmount, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $session->financialAccount->ledger_account_id,
            creditAccountId: $this->resolveId('4900'), // Cash Over / Misc Income
            amount: $varianceAmount,
            type: TransactionType::CashOver,
            occurredOn: new DateTimeImmutable($session->closed_at->format('Y-m-d')),
            description: 'Cash over at session close — counted exceeded expected',
            branchId: $session->branch_id,
            createdById: $userId,
            cashSessionId: $session->id,
            meta: ['alert' => true, 'variance_type' => 'over'],
        );
    }

    public function postCashShort(CashSession $session, string $varianceAmount, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('5900'), // Cash Short / Other
            creditAccountId: $session->financialAccount->ledger_account_id,
            amount: $varianceAmount,
            type: TransactionType::CashShort,
            occurredOn: new DateTimeImmutable($session->closed_at->format('Y-m-d')),
            description: 'Cash short at session close — expected exceeded counted',
            branchId: $session->branch_id,
            createdById: $userId,
            cashSessionId: $session->id,
            meta: ['alert' => true, 'variance_type' => 'short'],
        );
    }

    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }
}
