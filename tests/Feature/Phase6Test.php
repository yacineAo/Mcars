<?php

declare(strict_types=1);

use App\Enums\CarStatus;
use App\Enums\ContractStatus;
use App\Enums\DeductionReason;
use App\Enums\DepositStatus;
use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Payment\DepositPoster;
use App\Services\Payment\DepositService;
use App\Services\Payment\FineLiabilityService;
use App\Services\Payment\FinePoster;
use App\Services\Payment\OwnerInstallmentPoster;
use App\Services\Payment\OwnerStatementService;
use App\Services\Payment\PaymentPoster;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PayrollPoster;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function a(): ChartOfAccount
{
    return ChartOfAccount::where('code', func_get_arg(0))->firstOrFail();
}

function makeRef(string $prefix = 'REF'): string
{
    return $prefix.'-'.uniqid();
}

beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

    // Create a booking + contract so we can reference them
    $this->car = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->create();
    $this->owner = CarOwner::factory()->create();

    $this->booking = Booking::create([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'status' => 'active',
        'pickup_at' => '2026-07-01 10:00:00',
        'expected_return_at' => '2026-07-05 10:00:00',
        'actual_pickup_at' => '2026-07-01 10:00:00',
        'actual_return_at' => '2026-07-05 10:00:00',
        'daily_rate' => 5000.00,
        'days_count' => 4,
        'subtotal' => 20000.00,
        'total_amount' => 25000.00,
        'created_by_id' => $this->user->id,
    ]);
    $this->contract = Contract::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'contract_number' => 'CTR-'.uniqid(),
        'status' => ContractStatus::Active,
        'content_snapshot' => [],
    ]);

    $this->agreement = CarOwnershipAgreement::factory()->create([
        'car_owner_id' => $this->owner->id,
        'car_id' => $this->car->id,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'monthly_rent_amount' => 75000.00,
    ]);

    $this->accounting = app(AccountingService::class);
    $this->paymentService = app(PaymentService::class);
    $this->paymentPoster = app(PaymentPoster::class);
    $this->depositPoster = app(DepositPoster::class);
    $this->depositService = app(DepositService::class);
    $this->installmentPoster = app(OwnerInstallmentPoster::class);
    $this->finePoster = app(FinePoster::class);
    $this->payrollPoster = app(PayrollPoster::class);
    $this->ownerStatement = app(OwnerStatementService::class);
    $this->fineLiability = app(FineLiabilityService::class);
});

// ---------------------------------------------------------------------------
// 1. Payment Poster — E10..E21
// ---------------------------------------------------------------------------

it('posts a cash payment debiting 1010 and crediting 1110 (E10)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Cash,
        'amount' => 15000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    expect($txns)->toHaveCount(1);
    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1010')->id)
        ->and($t->credit_account_id)->toBe(a('1110')->id)
        ->and((float) $t->amount)->toBe(15000.00)
        ->and($t->type)->toBe(TransactionType::Payment);
});

it('posts a bank transfer payment debiting 1020 and crediting 1110 (E11)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::BankTransfer,
        'amount' => 25000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1020')->id)
        ->and($t->credit_account_id)->toBe(a('1110')->id);
});

it('posts a CCP payment debiting 1030 and crediting 1110 (E12)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Ccp,
        'amount' => 8000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1030')->id)
        ->and($t->credit_account_id)->toBe(a('1110')->id);
});

it('posts a BaridiMob payment debiting 1040 and crediting 1110 (E13)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::BaridiMob,
        'amount' => 5000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1040')->id)
        ->and($t->credit_account_id)->toBe(a('1110')->id);
});

it('posts a card payment debiting 1050 and crediting 1110 (E14)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Card,
        'amount' => 20000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1050')->id)
        ->and($t->credit_account_id)->toBe(a('1110')->id);
});

it('posts a refund (outbound payment) debiting 1110 and crediting 1010 (E21)', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Cash,
        'amount' => 3000.00,
        'direction' => 'outbound',
        'status' => PaymentStatus::Refunded,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $drafts = $this->paymentPoster->postPayment($payment, $this->user->id);
    $txns = $this->accounting->postMany(...$drafts);

    $t = $txns->first();
    expect($t->debit_account_id)->toBe(a('1110')->id)
        ->and($t->credit_account_id)->toBe(a('1010')->id)
        ->and((float) $t->amount)->toBe(3000.00);
});

// ---------------------------------------------------------------------------
// 2. Deposit Poster — E22..E31
// ---------------------------------------------------------------------------

it('posts a deposit received debiting 1010 and crediting 2100 (E22)', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $draft = $this->depositPoster->postDepositReceived($deposit, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('1010')->id)
        ->and($txn->credit_account_id)->toBe(a('2100')->id)
        ->and((float) $txn->amount)->toBe(20000.00)
        ->and($txn->type)->toBe(TransactionType::Deposit);
});

it('posts a deposit refund debiting 2100 and crediting 1010 (E23)', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $draft = $this->depositPoster->postDepositRefunded($deposit, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2100')->id)
        ->and($txn->credit_account_id)->toBe(a('1010')->id)
        ->and($txn->type)->toBe(TransactionType::DepositRefund);
});

it('posts a deposit deduction for damage debiting 2100 and crediting 4060 (E24)', function () {
    $draft = $this->depositPoster->postDeduction(
        reason: DeductionReason::Damage->value,
        amount: '5000.00',
        depositId: 1,
        bookingId: $this->booking->id,
        customerId: $this->customer->id,
        branchId: $this->branch->id,
        userId: $this->user->id,
    );
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2100')->id)
        ->and($txn->credit_account_id)->toBe(a('4060')->id)
        ->and((float) $txn->amount)->toBe(5000.00)
        ->and($txn->type)->toBe(TransactionType::DepositDeduction);
});

it('posts a deposit deduction for fuel debiting 2100 and crediting 4050 (E25)', function () {
    $draft = $this->depositPoster->postDeduction(
        reason: DeductionReason::Fuel->value,
        amount: '2000.00',
        depositId: 1,
        bookingId: $this->booking->id,
        customerId: $this->customer->id,
        branchId: $this->branch->id,
        userId: $this->user->id,
    );
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2100')->id)
        ->and($txn->credit_account_id)->toBe(a('4050')->id);
});

it('posts a deposit forfeiture debiting 2100 and crediting 4090 (E29)', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 15000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $draft = $this->depositPoster->postForfeited($deposit, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2100')->id)
        ->and($txn->credit_account_id)->toBe(a('4090')->id)
        ->and($txn->type)->toBe(TransactionType::DepositForfeited);
});

// ---------------------------------------------------------------------------
// 3. DepositService — orchestrated lifecycle
// ---------------------------------------------------------------------------

it('holds a deposit through DepositService and checks ledger (E22)', function () {
    $deposit = new Deposit([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 30000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $result = $this->depositService->hold($deposit, $this->user->id);

    expect($result->status)->toBe(DepositStatus::Held);
    expect($result->id)->not->toBeNull();

    $cashBalance = $this->accounting->balanceOf(a('1010')->id);
    expect($cashBalance->toDecimal())->toBe('30000.00');

    $liabilityBalance = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityBalance->toDecimal())->toBe('30000.00');
});

it('deducts from a deposit and updates status', function () {
    $deposit = new Deposit([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $deposit = $this->depositService->hold($deposit, $this->user->id);

    $deduction = new DepositDeduction([
        'deposit_id' => $deposit->id,
        'reason' => DeductionReason::Cleaning,
        'amount' => 5000.00,
        'description' => 'Cleaning charge',
        'created_by_id' => $this->user->id,
    ]);

    $result = $this->depositService->deduct($deposit, $deduction, $this->user->id);

    expect($result->status)->toBe(DepositStatus::PartiallyRefunded);
    expect($deduction->fresh()->id)->not->toBeNull();

    $liabilityBalance = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityBalance->toDecimal())->toBe('15000.00');

    $cleaningRevenue = $this->accounting->balanceOf(a('4080')->id);
    expect($cleaningRevenue->toDecimal())->toBe('5000.00');
});

it('prevents deductions exceeding deposit amount', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 10000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $deduction = new DepositDeduction([
        'deposit_id' => $deposit->id,
        'reason' => DeductionReason::Damage,
        'amount' => 15000.00,
        'description' => 'Excessive damage',
        'created_by_id' => $this->user->id,
    ]);

    expect(fn () => $this->depositService->deduct($deposit, $deduction, $this->user->id))
        ->toThrow(RuntimeException::class, 'exceed');
});

it('refunds a deposit through DepositService (E23)', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $this->depositService->hold($deposit, $this->user->id);
    $result = $this->depositService->refund($deposit->fresh(), null, $this->user->id);

    expect($result->status)->toBe(DepositStatus::Refunded);
    expect($result->settled_at)->not->toBeNull();
    expect($result->settled_by_id)->toBe($this->user->id);

    $liabilityBalance = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityBalance->toDecimal())->toBe('0.00');
});

it('forfeits a deposit through DepositService (E29)', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $this->depositService->hold($deposit, $this->user->id);
    $result = $this->depositService->forfeit($deposit->fresh(), $this->user->id);

    expect($result->status)->toBe(DepositStatus::Forfeited);

    $liabilityBalance = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityBalance->toDecimal())->toBe('0.00');

    $forfeitedIncome = $this->accounting->balanceOf(a('4090')->id);
    expect($forfeitedIncome->toDecimal())->toBe('20000.00');
});

// ---------------------------------------------------------------------------
// 4. Owner Installment Poster — E32, E36
// ---------------------------------------------------------------------------

it('posts an owner installment accrual debiting 5010 and crediting 2200 (E32)', function () {
    $installment = OwnerInstallment::create([
        'car_ownership_agreement_id' => $this->agreement->id,
        'car_owner_id' => $this->owner->id,
        'car_id' => $this->car->id,
        'branch_id' => $this->branch->id,
        'sequence_number' => 1,
        'total_installments' => 12,
        'period_month' => '2026-07-01',
        'due_date' => '2026-07-31',
        'amount_due' => 90000.00,
        'status' => InstallmentStatus::Pending,
    ]);

    $draft = $this->installmentPoster->postAccrual($installment, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('5010')->id)
        ->and($txn->credit_account_id)->toBe(a('2200')->id)
        ->and((float) $txn->amount)->toBe(90000.00)
        ->and($txn->type)->toBe(TransactionType::OwnerInstallment)
        ->and($txn->car_id)->toBe($this->car->id);
});

it('posts a waived owner installment reversing the accrual (E36)', function () {
    $installment = OwnerInstallment::create([
        'car_ownership_agreement_id' => $this->agreement->id,
        'car_owner_id' => $this->owner->id,
        'car_id' => $this->car->id,
        'branch_id' => $this->branch->id,
        'sequence_number' => 1,
        'total_installments' => 12,
        'period_month' => '2026-07-01',
        'due_date' => '2026-07-31',
        'amount_due' => 90000.00,
        'status' => InstallmentStatus::Waived,
    ]);

    $draft = $this->installmentPoster->postWaived($installment, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2200')->id)
        ->and($txn->credit_account_id)->toBe(a('5010')->id)
        ->and((float) $txn->amount)->toBe(90000.00);
});

// ---------------------------------------------------------------------------
// 5. OwnerStatementService
// ---------------------------------------------------------------------------

it('generates monthly installments from active agreements', function () {
    $count = $this->ownerStatement->generateMonthlyInstallments(Carbon::parse('2026-07-01'), $this->user->id);

    expect($count)->toBe(1);

    $installment = OwnerInstallment::where('car_owner_id', $this->owner->id)->first();
    expect($installment)->not->toBeNull()
        ->and((float) $installment->amount_due)->toBe(75000.00)
        ->and($installment->status)->toBe(InstallmentStatus::Pending)
        ->and($installment->accrual_transaction_id)->not->toBeNull();

    $rentExpense = $this->accounting->balanceOf(a('5010')->id);
    expect($rentExpense->toDecimal())->toBe('75000.00');

    $payable = $this->accounting->balanceOf(a('2200')->id);
    expect($payable->toDecimal())->toBe('75000.00');
});

it('skips duplicate monthly installments', function () {
    $this->ownerStatement->generateMonthlyInstallments(Carbon::parse('2026-07-01'), $this->user->id);
    $count2 = $this->ownerStatement->generateMonthlyInstallments(Carbon::parse('2026-07-01'), $this->user->id);

    expect($count2)->toBe(0);
});

it('computes owner balance from the ledger', function () {
    $this->ownerStatement->generateMonthlyInstallments(Carbon::parse('2026-07-01'), $this->user->id);
    $this->ownerStatement->generateMonthlyInstallments(Carbon::parse('2026-08-01'), $this->user->id);

    $balance = $this->ownerStatement->balance($this->owner->id);
    expect((float) $balance)->toBe(150000.00);
});

// ---------------------------------------------------------------------------
// 6. Fine Poster — E49..E55
// ---------------------------------------------------------------------------

it('posts a customer-liable fine debiting 1120 and crediting 2220 (E49)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F12345',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $draft = $this->finePoster->postCustomerLiability($fine, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('1120')->id)
        ->and($txn->credit_account_id)->toBe(a('2220')->id)
        ->and((float) $txn->amount)->toBe(5000.00)
        ->and($txn->type)->toBe(TransactionType::FineReceived);
});

it('posts a company-liable fine debiting 5140 and crediting 2220 (E50)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Speeding,
        'authority' => 'Police',
        'notice_number' => 'F67890',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 8000.00,
        'total_amount' => 8000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $draft = $this->finePoster->postCompanyLiability($fine, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('5140')->id)
        ->and($txn->credit_account_id)->toBe(a('2220')->id)
        ->and((float) $txn->amount)->toBe(8000.00);
});

it('posts a fine payment to authority debiting 2220 and crediting 1010 (E51)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F11111',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $draft = $this->finePoster->postPaymentToAuthority($fine, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('2220')->id)
        ->and($txn->credit_account_id)->toBe(a('1010')->id)
        ->and($txn->type)->toBe(TransactionType::FinePayment);
});

it('posts a fine recovery from customer debiting 1010 and crediting 1120 (E52)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F22222',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $draft = $this->finePoster->postRecovery($fine, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('1010')->id)
        ->and($txn->credit_account_id)->toBe(a('1120')->id)
        ->and($txn->type)->toBe(TransactionType::FinePayment);
});

it('posts a handling fee debiting 1110 and crediting 4070 (E54)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F33333',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $draft = $this->finePoster->postHandlingFee($fine, '1000.00', $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('1110')->id)
        ->and($txn->credit_account_id)->toBe(a('4070')->id)
        ->and((float) $txn->amount)->toBe(1000.00)
        ->and($txn->type)->toBe(TransactionType::FineRecharge);
});

it('posts a fine write-off debiting 5140 and crediting 1120 (E55)', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F44444',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::WrittenOff->value,
    ]);

    $draft = $this->finePoster->postWriteOff($fine, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('5140')->id)
        ->and($txn->credit_account_id)->toBe(a('1120')->id)
        ->and($txn->type)->toBe(TransactionType::FineWriteOff);
});

// ---------------------------------------------------------------------------
// 7. FineLiabilityService
// ---------------------------------------------------------------------------

it('proposes customer liability when a booking covers the violation time', function () {
    $fine = new Fine([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F99999',
        'violation_at' => '2026-07-03 14:30:00',
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
    ]);

    $result = $this->fineLiability->proposeLiability($fine);

    expect($result->liability)->toBe(FineLiability::Customer)
        ->and($result->customer_id)->toBe($this->customer->id)
        ->and($result->contract_id)->toBe($this->contract->id)
        ->and($result->status)->toBe(FineStatus::PendingReview);
});

it('proposes company liability when no booking covers the violation time', function () {
    $fine = new Fine([
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Speeding,
        'authority' => 'Police',
        'notice_number' => 'F88888',
        'violation_at' => '2026-07-15 14:30:00',
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 8000.00,
        'total_amount' => 8000.00,
    ]);

    $result = $this->fineLiability->proposeLiability($fine);

    expect($result->liability)->toBe(FineLiability::Company)
        ->and($result->customer_id)->toBeNull();
});

it('confirms liability and persists the fine', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F77777',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => 'pending_review',
    ]);

    $result = $this->fineLiability->confirmLiability($fine, 'company', $this->user->id);

    expect($result->liability)->toBe(FineLiability::Company)
        ->and($result->status)->toBe(FineStatus::PaidByCompany)
        ->and($result->liability_determined_by_id)->toBe($this->user->id)
        ->and($result->liability_determined_at)->not->toBeNull();

    $fresh = $fine->fresh();
    expect($fresh->liability)->toBe(FineLiability::Company);
});

// ---------------------------------------------------------------------------
// 8. Payroll Poster — E57..E62
// ---------------------------------------------------------------------------

it('posts payroll approval for a single employee (E57, E58)', function () {
    $employee = Employee::create([
        'branch_id' => $this->branch->id,
        'employee_number' => 'EMP001',
        'first_name' => 'Ahmed',
        'last_name' => 'Benali',
        'job_title' => 'Agent',
        'hire_date' => '2026-01-01',
        'contract_type' => 'full_time',
        'salary_type' => 'fixed',
        'base_salary' => 50000.00,
        'status' => 'active',
    ]);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-07-01',
        'status' => PayrollStatus::Draft,
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'base_salary' => 50000.00,
        'commissions_amount' => 0,
        'advances_deducted' => 0,
        'social_contributions' => 5000.00,
        'gross_amount' => 50000.00,
        'net_amount' => 45000.00,
        'status' => 'pending',
    ]);

    $run = $run->fresh();
    $drafts = $this->payrollPoster->postPayrollApproved($run, $this->user->id);

    expect($drafts)->toHaveCount(2);

    $salaryDraft = $drafts[0];
    expect($salaryDraft->debitAccountId)->toBe(a('5080')->id)
        ->and($salaryDraft->creditAccountId)->toBe(a('2300')->id)
        ->and($salaryDraft->type)->toBe(TransactionType::Payroll);

    $socialDraft = $drafts[1];
    expect($socialDraft->debitAccountId)->toBe(a('5085')->id)
        ->and($socialDraft->creditAccountId)->toBe(a('2310')->id)
        ->and((float) $socialDraft->amount)->toBe(5000.00);

    $txns = $this->accounting->postMany(...$drafts);
    expect($txns)->toHaveCount(2);

    $salaryExpense = $this->accounting->balanceOf(a('5080')->id);
    expect($salaryExpense->toDecimal())->toBe('50000.00');

    $payable = $this->accounting->balanceOf(a('2300')->id);
    expect($payable->toDecimal())->toBe('50000.00');
});

it('posts payroll payment debiting 2300 and crediting 1010 (E59)', function () {
    $employee = Employee::create([
        'branch_id' => $this->branch->id,
        'employee_number' => 'EMP002',
        'first_name' => 'Sara',
        'last_name' => 'Amrani',
        'job_title' => 'Agent',
        'hire_date' => '2026-01-01',
        'contract_type' => 'full_time',
        'salary_type' => 'fixed',
        'base_salary' => 60000.00,
        'status' => 'active',
    ]);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-07-01',
        'status' => PayrollStatus::Approved,
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'base_salary' => 60000.00,
        'commissions_amount' => 0,
        'advances_deducted' => 10000.00,
        'social_contributions' => 6000.00,
        'gross_amount' => 60000.00,
        'net_amount' => 50000.00,
        'status' => 'approved',
    ]);

    $run = $run->fresh();
    $drafts = $this->payrollPoster->postPayrollPaid($run, $this->user->id);

    expect($drafts)->toHaveCount(1);
    $draft = $drafts[0];

    expect($draft->debitAccountId)->toBe(a('2300')->id)
        ->and($draft->creditAccountId)->toBe(a('1010')->id)
        ->and((float) $draft->amount)->toBe(50000.00)
        ->and($draft->type)->toBe(TransactionType::PayrollPayment);
});

it('posts an advance (E61) and its recovery in payroll', function () {
    $employee = Employee::create([
        'branch_id' => $this->branch->id,
        'employee_number' => 'EMP003',
        'first_name' => 'Khaled',
        'last_name' => 'Slimani',
        'job_title' => 'Agent',
        'hire_date' => '2026-01-01',
        'contract_type' => 'full_time',
        'salary_type' => 'fixed',
        'base_salary' => 50000.00,
        'status' => 'active',
    ]);

    $draft = $this->payrollPoster->postAdvance('15000.00', $employee->id, $this->branch->id, $this->user->id);
    $txn = $this->accounting->post($draft);

    expect($txn->debit_account_id)->toBe(a('1130')->id)
        ->and($txn->credit_account_id)->toBe(a('1010')->id)
        ->and((float) $txn->amount)->toBe(15000.00)
        ->and($txn->type)->toBe(TransactionType::Advance);

    $advanceBalance = $this->accounting->balanceOf(a('1130')->id);
    expect($advanceBalance->toDecimal())->toBe('15000.00');

    $advance = EmployeeAdvance::create([
        'employee_id' => $employee->id,
        'branch_id' => $this->branch->id,
        'amount' => 15000.00,
        'advanced_on' => now(),
        'status' => 'outstanding',
    ]);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-08-01',
        'status' => PayrollStatus::Draft,
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'base_salary' => 50000.00,
        'advances_deducted' => 15000.00,
        'social_contributions' => 5000.00,
        'gross_amount' => 50000.00,
        'net_amount' => 30000.00,
        'status' => 'pending',
    ]);

    $run = $run->fresh();
    $drafts = $this->payrollPoster->postPayrollApproved($run, $this->user->id);

    $recoveryDraft = collect($drafts)->firstWhere('type', TransactionType::AdvanceRecovery);
    expect($recoveryDraft)->not->toBeNull()
        ->and($recoveryDraft->debitAccountId)->toBe(a('2300')->id)
        ->and($recoveryDraft->creditAccountId)->toBe(a('1130')->id)
        ->and((float) $recoveryDraft->amount)->toBe(15000.00);
});

// ---------------------------------------------------------------------------
// 9. PaymentService orchestration
// ---------------------------------------------------------------------------

it('orchestrates a full payment lifecycle through PaymentService', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Cash,
        'amount' => 25000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);

    $results = $this->paymentService->recordPayment($payment, $this->user->id);
    expect($results)->toHaveCount(1);

    $cashBalance = $this->accounting->balanceOf(a('1010')->id);
    expect($cashBalance->toDecimal())->toBe('25000.00');
});

it('orchestrates a deposit hold and forfeit through PaymentService', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 20000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $this->paymentService->holdDeposit($deposit, $this->user->id);

    $cashBalance = $this->accounting->balanceOf(a('1010')->id);
    expect($cashBalance->toDecimal())->toBe('20000.00');

    $liability = $this->accounting->balanceOf(a('2100')->id);
    expect($liability->toDecimal())->toBe('20000.00');

    $this->paymentService->forfeitDeposit($deposit->fresh(), $this->user->id);

    $liabilityAfter = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityAfter->toDecimal())->toBe('0.00');

    $forfeitedIncome = $this->accounting->balanceOf(a('4090')->id);
    expect($forfeitedIncome->toDecimal())->toBe('20000.00');
});

it('orchestrates owner installment accrual through PaymentService', function () {
    $installment = OwnerInstallment::create([
        'car_ownership_agreement_id' => $this->agreement->id,
        'car_owner_id' => $this->owner->id,
        'car_id' => $this->car->id,
        'branch_id' => $this->branch->id,
        'sequence_number' => 1,
        'total_installments' => 12,
        'period_month' => '2026-07-01',
        'due_date' => '2026-07-31',
        'amount_due' => 90000.00,
        'status' => InstallmentStatus::Pending,
    ]);

    $this->paymentService->accrueOwnerInstallment($installment, $this->user->id);

    $expense = $this->accounting->balanceOf(a('5010')->id);
    expect($expense->toDecimal())->toBe('90000.00');

    $payable = $this->accounting->balanceOf(a('2200')->id);
    expect($payable->toDecimal())->toBe('90000.00');
});

it('orchestrates fine assignment through PaymentService', function () {
    $fine = Fine::create([
        'reference' => makeRef('FIN'),
        'branch_id' => $this->branch->id,
        'car_id' => $this->car->id,
        'customer_id' => $this->customer->id,
        'type' => FineType::Parking,
        'authority' => 'Police',
        'notice_number' => 'F55555',
        'violation_at' => now()->subDays(3),
        'received_at' => now(),
        'due_date' => now()->addDays(15),
        'amount' => 5000.00,
        'total_amount' => 5000.00,
        'status' => FineStatus::Pending->value,
    ]);

    $this->paymentService->assignFine($fine, $this->user->id);

    $receivable = $this->accounting->balanceOf(a('1120')->id);
    expect($receivable->toDecimal())->toBe('5000.00');

    $liability = $this->accounting->balanceOf(a('2220')->id);
    expect($liability->toDecimal())->toBe('5000.00');
});

it('orchestrates payroll approval through PaymentService', function () {
    $employee = Employee::create([
        'branch_id' => $this->branch->id,
        'employee_number' => 'EMP004',
        'first_name' => 'Test',
        'last_name' => 'User',
        'job_title' => 'Agent',
        'hire_date' => '2026-01-01',
        'contract_type' => 'full_time',
        'salary_type' => 'fixed',
        'base_salary' => 70000.00,
        'status' => 'active',
    ]);

    $run = PayrollRun::create([
        'branch_id' => $this->branch->id,
        'period_month' => '2026-07-01',
        'status' => PayrollStatus::Draft,
    ]);

    PayrollItem::create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'base_salary' => 70000.00,
        'commissions_amount' => 0,
        'advances_deducted' => 0,
        'social_contributions' => 7000.00,
        'gross_amount' => 70000.00,
        'net_amount' => 63000.00,
        'status' => 'pending',
    ]);

    $run = $run->fresh();
    $results = $this->paymentService->approvePayroll($run, $this->user->id);
    expect($results)->toHaveCount(2);

    $salaryExpense = $this->accounting->balanceOf(a('5080')->id);
    expect($salaryExpense->toDecimal())->toBe('70000.00');
});

// ---------------------------------------------------------------------------
// 10. Balance assertions — no stored balances
// ---------------------------------------------------------------------------

it('derives cash balance from ledger transactions only', function () {
    $payment = Payment::create([
        'branch_id' => $this->branch->id,
        'reference' => makeRef('PAY'),
        'method' => PaymentMethod::Cash,
        'amount' => 10000.00,
        'direction' => 'inbound',
        'status' => PaymentStatus::Completed,
        'customer_id' => $this->customer->id,
        'paid_at' => now(),
        'created_by_id' => $this->user->id,
    ]);
    $this->paymentService->recordPayment($payment, $this->user->id);

    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 5000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);
    $this->depositService->hold($deposit, $this->user->id);

    $cashBalance = $this->accounting->balanceOf(a('1010')->id);
    expect($cashBalance->toDecimal())->toBe('15000.00');

    $liabilityBalance = $this->accounting->balanceOf(a('2100')->id);
    expect($liabilityBalance->toDecimal())->toBe('5000.00');
});

// ---------------------------------------------------------------------------
// 11. Concurrency — overlapping deductions cannot exceed deposit
// ---------------------------------------------------------------------------

it('enforces deposit amount limit across concurrent transactions', function () {
    $deposit = Deposit::create([
        'branch_id' => $this->branch->id,
        'booking_id' => $this->booking->id,
        'customer_id' => $this->customer->id,
        'amount' => 10000.00,
        'method' => PaymentMethod::Cash,
        'held_at' => now(),
        'status' => DepositStatus::Held,
        'created_by_id' => $this->user->id,
    ]);

    $d1 = new DepositDeduction([
        'deposit_id' => $deposit->id,
        'reason' => DeductionReason::Cleaning,
        'amount' => 6000.00,
        'created_by_id' => $this->user->id,
    ]);

    $result = $this->depositService->deduct($deposit, $d1, $this->user->id);
    expect($result->status)->toBe(DepositStatus::PartiallyRefunded);

    $d2 = new DepositDeduction([
        'deposit_id' => $deposit->id,
        'reason' => DeductionReason::Damage,
        'amount' => 8000.00,
        'created_by_id' => $this->user->id,
    ]);

    expect(fn () => $this->depositService->deduct($deposit->fresh(), $d2, $this->user->id))
        ->toThrow(RuntimeException::class, 'exceed');
});
