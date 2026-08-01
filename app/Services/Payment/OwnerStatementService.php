<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Models\CarOwnershipAgreement;
use App\Models\ChartOfAccount;
use App\Models\OwnerInstallment;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class OwnerStatementService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly OwnerInstallmentPoster $poster,
    ) {}

    public function generateMonthlyInstallments(Carbon $periodMonth, int $userId): int
    {
        $startOfMonth = $periodMonth->copy()->startOfMonth();
        $activeAgreements = CarOwnershipAgreement::query()
            ->where('status', 'active')
            ->where('start_date', '<=', $startOfMonth)
            ->where(function ($q) use ($startOfMonth) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startOfMonth);
            })
            ->get();

        $generated = 0;

        foreach ($activeAgreements as $agreement) {
            if ($this->generateOneInstallment($agreement, $startOfMonth, $userId)) {
                $generated++;
            }
        }

        return $generated;
    }

    public function generateForAgreement(CarOwnershipAgreement $agreement, int $userId): int
    {
        if ($agreement->status !== AgreementStatus::Active) {
            return 0;
        }

        $generated = 0;
        $firstDue = $agreement->first_due_date
            ? Carbon::parse($agreement->first_due_date->format('Y-m-d'))
            : Carbon::parse($agreement->start_date->format('Y-m-d'))->startOfMonth();
        $installmentCount = $agreement->installments_count ?? 999;
        $existingMax = OwnerInstallment::where('car_ownership_agreement_id', $agreement->id)
            ->max('sequence_number') ?? 0;

        for ($i = $existingMax + 1; $i <= $installmentCount; $i++) {
            $periodMonth = $firstDue->copy()->addMonths($i - 1)->startOfMonth();

            if ($periodMonth->isPast() && $periodMonth->diffInMonths(now()) > 1) {
                continue;
            }

            if ($this->generateOneInstallment($agreement, $periodMonth, $userId)) {
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * The generator's own numbering rule, shared with the manual correction
     * path (CreateOwnerInstallment) so the document run cannot diverge.
     */
    public function nextSequenceNumber(int $agreementId): int
    {
        return (int) (OwnerInstallment::where('car_ownership_agreement_id', $agreementId)
            ->max('sequence_number') ?? 0) + 1;
    }

    private function generateOneInstallment(CarOwnershipAgreement $agreement, Carbon $periodMonth, int $userId): bool
    {
        $startOfMonth = $periodMonth->copy()->startOfMonth();

        $exists = OwnerInstallment::where('car_ownership_agreement_id', $agreement->id)
            ->where('period_month', $startOfMonth->format('Y-m-d'))
            ->exists();

        if ($exists) {
            return false;
        }

        $sequenceNumber = $this->nextSequenceNumber($agreement->id);

        $paymentDay = $agreement->payment_day_of_month ?? 5;
        $dueDate = $startOfMonth->copy()->addDays($paymentDay - 1);
        if ($dueDate->isPast()) {
            $dueDate = $startOfMonth->copy()->addMonth()->startOfMonth()->addDays($paymentDay - 1);
        }

        $amountDue = $agreement->model === AgreementModel::FixedMonthly
            ? $agreement->monthly_rent_amount
            : '0.00';

        $installment = OwnerInstallment::create([
            'car_ownership_agreement_id' => $agreement->id,
            'car_owner_id' => $agreement->car_owner_id,
            'car_id' => $agreement->car_id,
            'branch_id' => $agreement->branch_id,
            'sequence_number' => $sequenceNumber,
            // NULL means the agreement is open-ended — owner rent is a monthly
            // accrual that runs until the agreement ends, so there is no count.
            // The old 999 sentinel leaked into every screen as "3 of 999".
            'total_installments' => $agreement->installments_count,
            'period_month' => $startOfMonth->format('Y-m-d'),
            'due_date' => $dueDate->format('Y-m-d'),
            'amount_due' => $amountDue,
            'status' => 'pending',
        ]);

        // The accrual and the pointer stamp are one step: a crash between them
        // would leave E32 on the ledger while the row still reads unaccrued.
        $this->db->transaction(function () use ($installment, $userId): void {
            $transactions = $this->accounting->postMany(
                $this->poster->postAccrual($installment, $userId),
            );

            $installment->update([
                'accrual_transaction_id' => $transactions->first()?->id,
            ]);
        });

        return true;
    }

    public function balance(int $carOwnerId): string
    {
        $accountId = ChartOfAccount::where('code', '2200')->value('id');

        $credited = Transaction::where('credit_account_id', $accountId)
            ->where('car_owner_id', $carOwnerId)
            ->sum('amount');

        $debited = Transaction::where('debit_account_id', $accountId)
            ->where('car_owner_id', $carOwnerId)
            ->sum('amount');

        return (string) ((float) $credited - (float) $debited);
    }
}
