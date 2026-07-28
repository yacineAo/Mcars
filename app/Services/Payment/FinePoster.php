<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\Fine;
use App\Services\Accounting\TransactionDraft;
use DateTimeImmutable;

class FinePoster
{
    private function resolveId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->valueOrFail('id');
    }

    /** E49: Fine received, customer liable */
    public function postCustomerLiability(Fine $fine, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('1120'),
            creditAccountId: $this->resolveId('2220'),
            amount: (string) $fine->total_amount,
            type: TransactionType::FineReceived,
            occurredOn: new DateTimeImmutable($fine->received_at->format('Y-m-d')),
            description: 'Fine — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            carId: $fine->car_id,
            customerId: $fine->customer_id,
            bookingId: $fine->booking_id,
            contractId: $fine->contract_id,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }

    /** E50: Fine received, company liable */
    public function postCompanyLiability(Fine $fine, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('5140'),
            creditAccountId: $this->resolveId('2220'),
            amount: (string) $fine->total_amount,
            type: TransactionType::FineReceived,
            occurredOn: new DateTimeImmutable($fine->received_at->format('Y-m-d')),
            description: 'Fine (company) — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            carId: $fine->car_id,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }

    /** E51: Company pays the authority */
    public function postPaymentToAuthority(Fine $fine, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('2220'),
            creditAccountId: $this->resolveId('1010'),
            amount: (string) $fine->total_amount,
            type: TransactionType::FinePayment,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Fine paid to authority — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }

    /** E52: Recovered from customer */
    public function postRecovery(Fine $fine, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('1010'),
            creditAccountId: $this->resolveId('1120'),
            amount: (string) $fine->total_amount,
            type: TransactionType::FinePayment,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Fine recovered — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            customerId: $fine->customer_id,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }

    /** E54: Handling fee charged */
    public function postHandlingFee(Fine $fine, string $feeAmount, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('1110'),
            creditAccountId: $this->resolveId('4070'),
            amount: $feeAmount,
            type: TransactionType::FineRecharge,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Fine handling fee — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            customerId: $fine->customer_id,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }

    /** E55: Written off */
    public function postWriteOff(Fine $fine, int $userId): TransactionDraft
    {
        return new TransactionDraft(
            debitAccountId: $this->resolveId('5140'),
            creditAccountId: $this->resolveId('1120'),
            amount: (string) $fine->total_amount,
            type: TransactionType::FineWriteOff,
            occurredOn: new DateTimeImmutable('now'),
            description: 'Fine written off — '.$fine->notice_number,
            branchId: $fine->branch_id,
            createdById: $userId,
            sourceType: 'fine',
            sourceId: $fine->id,
            meta: ['fine_id' => (string) $fine->id],
        );
    }
}
