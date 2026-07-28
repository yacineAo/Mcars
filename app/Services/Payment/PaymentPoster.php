<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class PaymentPoster
{
    public function postPayment(Payment $payment, int $userId): array
    {
        $accountMap = [
            'cash' => '1010',
            'bank_transfer' => '1020',
            'ccp' => '1030',
            'baridimob' => '1040',
            'card' => '1050',
            'cheque' => '1050',
            'compensation' => '2500',
        ];

        $cashAccountCode = $accountMap[$payment->method] ?? '1010';

        $drafts = [];
        $occurredOn = new DateTimeImmutable($payment->paid_at->format('Y-m-d'));

        if ($payment->direction === 'inbound') {
            // E10–E14, E19: payment clears AR or creates credit balance
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId($cashAccountCode),
                creditAccountId: $this->resolveId('1110'),
                amount: (string) $payment->amount,
                type: TransactionType::Payment,
                occurredOn: $occurredOn,
                description: 'Payment — '.$payment->reference,
                branchId: $payment->branch_id,
                createdById: $userId,
                customerId: $payment->customer_id,
                carOwnerId: $payment->car_owner_id,
                employeeId: $payment->employee_id,
                cashSessionId: $payment->cash_session_id,
                paymentMethod: $payment->method,
                meta: ['payment_reference' => $payment->reference],
            );
        } else {
            // E21, E35: refund / outbound
            $drafts[] = new TransactionDraft(
                debitAccountId: $this->resolveId('1110'),
                creditAccountId: $this->resolveId($cashAccountCode),
                amount: (string) $payment->amount,
                type: TransactionType::Payment,
                occurredOn: $occurredOn,
                description: 'Refund — '.$payment->reference,
                branchId: $payment->branch_id,
                createdById: $userId,
                customerId: $payment->customer_id,
                carOwnerId: $payment->car_owner_id,
                employeeId: $payment->employee_id,
                cashSessionId: $payment->cash_session_id,
                paymentMethod: $payment->method,
                meta: ['payment_reference' => $payment->reference],
            );
        }

        return $drafts;
    }

    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }
}
