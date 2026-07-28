<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Services\Accounting\AccountingService;
use Illuminate\Database\DatabaseManager;

class DepositService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly DepositPoster $poster,
    ) {}

    public function hold(Deposit $deposit, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $userId) {
            $deposit->status = DepositStatus::Held;
            $deposit->save();

            $this->accounting->postMany(
                $this->poster->postDepositReceived($deposit, $userId),
            );

            return $deposit->fresh();
        });
    }

    public function deduct(Deposit $deposit, DepositDeduction $deduction, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $deduction, $userId) {
            $deduction->save();

            $totalDeducted = (float) $deposit->deductions()->sum('amount');

            if ($totalDeducted > (float) $deposit->amount) {
                $deduction->delete();
                throw new \RuntimeException('Deductions cannot exceed the deposit amount.');
            }

            $this->accounting->postMany(
                $this->poster->postDeduction(
                    reason: $deduction->reason instanceof \BackedEnum ? $deduction->reason->value : $deduction->reason,
                    amount: (string) $deduction->amount,
                    depositId: $deposit->id,
                    bookingId: $deposit->booking_id,
                    customerId: $deposit->customer_id,
                    branchId: $deposit->branch_id,
                    userId: $userId,
                ),
            );

            $remaining = (float) $deposit->amount - $totalDeducted;

            if ($remaining <= 0) {
                $deposit->status = DepositStatus::Forfeited;
            } else {
                $deposit->status = DepositStatus::PartiallyRefunded;
            }

            $deposit->save();

            return $deposit->fresh();
        });
    }

    public function refund(Deposit $deposit, ?string $amount, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $amount, $userId) {
            $totalDeducted = (float) $deposit->deductions()->sum('amount');
            $refundAmount = $amount ?? (string) ((float) $deposit->amount - $totalDeducted);

            if ((float) $refundAmount <= 0) {
                throw new \DomainException('Nothing to refund.');
            }

            $this->accounting->postMany(
                $this->poster->postDepositRefunded($deposit, $userId),
            );

            $deposit->status = DepositStatus::Refunded;
            $deposit->settled_at = now();
            $deposit->settled_by_id = $userId;
            $deposit->save();

            return $deposit->fresh();
        });
    }

    public function forfeit(Deposit $deposit, int $userId): Deposit
    {
        return $this->db->transaction(function () use ($deposit, $userId) {
            $this->accounting->postMany(
                $this->poster->postForfeited($deposit, $userId),
            );

            $deposit->status = DepositStatus::Forfeited;
            $deposit->settled_at = now();
            $deposit->settled_by_id = $userId;
            $deposit->save();

            return $deposit->fresh();
        });
    }
}
