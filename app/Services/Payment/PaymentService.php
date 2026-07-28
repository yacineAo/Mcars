<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Deposit;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class PaymentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly PaymentPoster $paymentPoster,
        private readonly DepositPoster $depositPoster,
        private readonly OwnerInstallmentPoster $installmentPoster,
        private readonly FinePoster $finePoster,
        private readonly PayrollPoster $payrollPoster,
    ) {}

    public function recordPayment(Payment $payment, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->paymentPoster->postPayment($payment, $userId),
        );
    }

    public function holdDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postDepositReceived($deposit, $userId),
        );
    }

    public function refundDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postDepositRefunded($deposit, $userId),
        );
    }

    public function forfeitDeposit(Deposit $deposit, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->depositPoster->postForfeited($deposit, $userId),
        );
    }

    public function accrueOwnerInstallment(OwnerInstallment $installment, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->installmentPoster->postAccrual($installment, $userId),
        );
    }

    public function assignFine(Fine $fine, int $userId): Collection
    {
        return $this->accounting->postMany(
            $this->finePoster->postCustomerLiability($fine, $userId),
        );
    }

    public function approvePayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->payrollPoster->postPayrollApproved($run, $userId),
        );
    }

    public function payPayroll(PayrollRun $run, int $userId): Collection
    {
        return $this->accounting->postMany(
            ...$this->payrollPoster->postPayrollPaid($run, $userId),
        );
    }
}
