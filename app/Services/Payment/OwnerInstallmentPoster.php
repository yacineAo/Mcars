<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\OwnerInstallment;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class OwnerInstallmentPoster
{
    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }

    /** E32: Monthly instalment accrued */
    public function postAccrual(OwnerInstallment $installment, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('5010'),
            creditAccountId: $this->resolveId('2200'),
            amount: (string) $installment->amount_due,
            type: TransactionType::OwnerInstallment,
            occurredOn: new DateTimeImmutable($installment->period_month->format('Y-m-d')),
            description: 'Owner rent — '.$installment->carOwner?->first_name.' '.$installment->period_month->format('Y-m'),
            branchId: $installment->branch_id,
            createdById: $userId,
            carId: $installment->car_id,
            carOwnerId: $installment->car_owner_id,
            sourceType: 'owner_installment',
            sourceId: $installment->id,
            meta: ['installment_id' => (string) $installment->id],
        );
    }

    /** E36: Instalment waived (reversal) */
    public function postWaived(OwnerInstallment $installment, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('2200'),
            creditAccountId: $this->resolveId('5010'),
            amount: (string) $installment->amount_due,
            type: TransactionType::OwnerInstallment,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Owner rent waived — '.$installment->period_month->format('Y-m'),
            branchId: $installment->branch_id,
            createdById: $userId,
            carId: $installment->car_id,
            carOwnerId: $installment->car_owner_id,
            sourceType: 'owner_installment',
            sourceId: $installment->id,
            meta: ['installment_id' => (string) $installment->id, 'waived' => 'true'],
        );
    }
}
