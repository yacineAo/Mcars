<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\Deposit;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class DepositPoster
{
    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }

    /** E22: Deposit received */
    public function postDepositReceived(Deposit $deposit, int $userId): TransactionDraft
    {
        $accountMap = [
            'cash' => '1010', 'bank_transfer' => '1020',
            'ccp' => '1030', 'baridimob' => '1040',
            'card' => '1050', 'cheque' => '1050',
        ];
        $cashCode = $accountMap[$deposit->method->value] ?? '1010';

        return new TransactionDraft(
            debitAccountId: $this->resolveId($cashCode),
            creditAccountId: $this->resolveId('2100'),
            amount: (string) $deposit->amount,
            type: TransactionType::Deposit,
            occurredOn: new DateTimeImmutable($deposit->held_at->format('Y-m-d')),
            description: 'Deposit received — booking '.$deposit->booking_id,
            branchId: $deposit->branch_id,
            createdById: $userId,
            customerId: $deposit->customer_id,
            bookingId: $deposit->booking_id,
            contractId: $deposit->contract_id,
            meta: ['deposit_id' => (string) $deposit->id],
        );
    }

    /** E23: Deposit refunded in full */
    public function postDepositRefunded(Deposit $deposit, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('2100'),
            creditAccountId: $this->resolveId('1010'),
            amount: (string) $deposit->amount,
            type: TransactionType::DepositRefund,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Deposit refunded — booking '.$deposit->booking_id,
            branchId: $deposit->branch_id,
            createdById: $userId,
            customerId: $deposit->customer_id,
            bookingId: $deposit->booking_id,
            contractId: $deposit->contract_id,
            meta: ['deposit_id' => (string) $deposit->id],
        );
    }

    /** E24–E28: Deduction posts from deposit to revenue */
    public function postDeduction(
        string $reason,
        string $amount,
        int $depositId,
        int $bookingId,
        int $customerId,
        ?int $branchId,
        int $userId,
    ): TransactionDraft {
        $revenueAccount = match ($reason) {
            'damage' => '4060',
            'fuel', 'missing_fuel' => '4050',
            'late_return' => '4030',
            'cleaning' => '4080',
            'traffic_fine' => '1120',
            'excess_mileage' => '4040',
            default => '4060',
        };

        return new TransactionDraft(
            debitAccountId: $this->resolveId('2100'),
            creditAccountId: $this->resolveId($revenueAccount),
            amount: $amount,
            type: TransactionType::DepositDeduction,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Deposit deduction — '.str_replace('_', ' ', $reason),
            branchId: $branchId,
            createdById: $userId,
            customerId: $customerId,
            bookingId: $bookingId,
            meta: ['deposit_id' => (string) $depositId, 'deduction_reason' => $reason],
        );
    }

    /** E29: Deposit forfeited entirely */
    public function postForfeited(Deposit $deposit, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('2100'),
            creditAccountId: $this->resolveId('4090'),
            amount: (string) $deposit->amount,
            type: TransactionType::DepositForfeited,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Deposit forfeited — booking '.$deposit->booking_id,
            branchId: $deposit->branch_id,
            createdById: $userId,
            customerId: $deposit->customer_id,
            bookingId: $deposit->booking_id,
            contractId: $deposit->contract_id,
            meta: ['deposit_id' => (string) $deposit->id],
        );
    }
}
