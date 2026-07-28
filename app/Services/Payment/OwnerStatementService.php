<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AgreementModel;
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
            $existingCount = OwnerInstallment::where('car_ownership_agreement_id', $agreement->id)
                ->where('period_month', $startOfMonth->format('Y-m-d'))
                ->count();

            if ($existingCount > 0) {
                continue;
            }

            $sequenceNumber = OwnerInstallment::where('car_ownership_agreement_id', $agreement->id)->max('sequence_number') ?? 0;
            $sequenceNumber++;

            $paymentDay = $agreement->payment_day_of_month ?? 5;
            $dueDate = $startOfMonth->copy()->addDays($paymentDay);
            if ($dueDate->isPast()) {
                $dueDate = $startOfMonth->copy()->addMonth()->startOfMonth()->addDays($paymentDay);
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
                'total_installments' => 999,
                'period_month' => $startOfMonth->format('Y-m-d'),
                'due_date' => $dueDate->format('Y-m-d'),
                'amount_due' => $amountDue,
                'status' => 'pending',
            ]);

            $transactions = $this->accounting->postMany(
                $this->poster->postAccrual($installment, $userId),
            );

            $installment->update([
                'accrual_transaction_id' => $transactions->first()?->id,
            ]);

            $generated++;
        }

        return $generated;
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
