<?php

declare(strict_types=1);

use App\Enums\CashSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\ExpensePoster;
use App\Services\Accounting\TransactionDraft;
use App\Services\CashRegisterService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Resolve a COA account by code. */
function account(string $code): ChartOfAccount
{
    return ChartOfAccount::where('code', $code)->firstOrFail();
}

/** Create a branch + user + chart of accounts once for all ledger tests. */
beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

    $this->service = app(AccountingService::class);
});

// ---------------------------------------------------------------------------
// 1. AccountingService — posting
// ---------------------------------------------------------------------------

it('posts a single balanced transaction', function () {
    $txn = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '15000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Rental revenue',
        createdById: $this->user->id,
    ));

    expect($txn)->toBeInstanceOf(Transaction::class)
        ->and($txn->reference)->toMatch('/^TRX-/')
        ->and((float) $txn->amount)->toBe(15000.00)
        ->and($txn->debit_account_id)->toBe(account('1010')->id)
        ->and($txn->credit_account_id)->toBe(account('4010')->id)
        ->and($txn->is_reversal)->toBeFalse();
});

it('posts multiple transactions atomically with postMany', function () {
    $results = $this->service->postMany(
        new TransactionDraft(
            debitAccountId: account('1010')->id,
            creditAccountId: account('4010')->id,
            amount: '15000.00',
            type: TransactionType::RentalRevenue,
            occurredOn: new DateTimeImmutable,
            createdById: $this->user->id,
        ),
        new TransactionDraft(
            debitAccountId: account('1010')->id,
            creditAccountId: account('4010')->id,
            amount: '3000.00',
            type: TransactionType::ExtrasRevenue,
            occurredOn: new DateTimeImmutable,
            createdById: $this->user->id,
        ),
    );

    expect($results)->toHaveCount(2);
    $meta = $results[0]->meta;
    expect($meta)->toHaveKey('group_uuid')
        ->and($results[1]->meta['group_uuid'])->toBe($meta['group_uuid']);
});

it('rejects posting with amount zero or negative', function () {
    expect(fn () => $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '0',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
    )))->toThrow(RuntimeException::class, 'must be positive');

    expect(fn () => $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '-100',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
    )))->toThrow(RuntimeException::class, 'must be positive');
});

it('rejects posting with same debit and credit account', function () {
    $id = account('1010')->id;
    expect(fn () => $this->service->post(new TransactionDraft(
        debitAccountId: $id,
        creditAccountId: $id,
        amount: '1000',
        type: TransactionType::Adjustment,
        occurredOn: new DateTimeImmutable,
    )))->toThrow(RuntimeException::class, 'must be different');
});

it('rejects posting to a non-postable account', function () {
    $heading = ChartOfAccount::where('is_postable', false)->first();
    expect($heading)->not->toBeNull();

    expect(fn () => $this->service->post(new TransactionDraft(
        debitAccountId: $heading->id,
        creditAccountId: account('4010')->id,
        amount: '1000',
        type: TransactionType::Adjustment,
        occurredOn: new DateTimeImmutable,
    )))->toThrow(RuntimeException::class, 'not postable');
});

// ---------------------------------------------------------------------------
// 2. Balance queries
// ---------------------------------------------------------------------------

it('computes balance from the ledger', function () {
    // Post two debits to cash
    $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '30000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable('2026-07-01'),
        createdById: $this->user->id,
    ));

    $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '15000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable('2026-07-15'),
        createdById: $this->user->id,
    ));

    // Cash (debit normal balance) should show 45000
    $balance = $this->service->balanceOf(account('1010')->id);
    expect($balance->toDecimal())->toBe('45000.00');

    // Revenue (credit normal balance) should show 45000
    $revenueBalance = $this->service->balanceOf(account('4010')->id);
    expect($revenueBalance->toDecimal())->toBe('45000.00');

    // Balance as-of before second posting should show 30000
    $asOfBalance = $this->service->balanceOf(account('1010')->id, '2026-07-10');
    expect($asOfBalance->toDecimal())->toBe('30000.00');
});

// ---------------------------------------------------------------------------
// 3. Reversals
// ---------------------------------------------------------------------------

it('reverses a transaction and restores the prior balance exactly', function () {
    $txn = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '10000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable('2026-07-01'),
        createdById: $this->user->id,
    ));

    $beforeBalance = $this->service->balanceOf(account('1010')->id);

    $reversal = $this->service->reverse($txn, 'Test reversal', $this->user);

    expect($reversal->is_reversal)->toBeTrue()
        ->and($reversal->reverses_transaction_id)->toBe($txn->id)
        ->and($reversal->debit_account_id)->toBe(account('4010')->id) // swap
        ->and($reversal->credit_account_id)->toBe(account('1010')->id)
        ->and((float) $reversal->amount)->toBe(10000.00);

    // Balance should be back to zero
    $afterBalance = $this->service->balanceOf(account('1010')->id);
    expect($afterBalance->toDecimal())->toBe('0.00');
});

it('cannot reverse a reversal', function () {
    $txn = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '10000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    $reversal = $this->service->reverse($txn, 'First reversal', $this->user);

    expect(fn () => $this->service->reverse($reversal, 'Double reversal'))
        ->toThrow(RuntimeException::class, 'Cannot reverse a reversal');
});

// ---------------------------------------------------------------------------
// 4. Immutability — DB trigger
// ---------------------------------------------------------------------------

it('blocks direct update on transactions at the database level', function () {
    $txn = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '5000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    expect(fn () => DB::table('transactions')
        ->where('id', $txn->id)
        ->update(['description' => 'changed']))
        ->toThrow(QueryException::class);
});

it('blocks direct delete on transactions at the database level', function () {
    $txn = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '5000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    expect(fn () => DB::table('transactions')
        ->where('id', $txn->id)
        ->delete())
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// 5. Expense lifecycle
// ---------------------------------------------------------------------------

it('creates an expense through draft → approve → pay flow', function () {
    $category = ExpenseCategory::first();
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $expense = Expense::create([
        'branch_id' => $this->branch->id,
        'expense_category_id' => $category->id,
        'amount' => 5000,
        'total_amount' => 5000,
        'incurred_on' => now(),
        'description' => 'Test expense',
        'status' => ExpenseStatus::Draft,
        'payment_method' => PaymentMethod::Cash,
    ]);

    expect($expense->status)->toBe(ExpenseStatus::Draft);

    // Submit for approval
    $expense->status = ExpenseStatus::PendingApproval;
    $expense->save();
    expect($expense->fresh()->status)->toBe(ExpenseStatus::PendingApproval);

    // Approve
    $expense->status = ExpenseStatus::Approved;
    $expense->approved_by_id = $this->user->id;
    $expense->approved_at = now();
    $expense->save();
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved);

    // Pay — this posts to the ledger
    $poster = app(ExpensePoster::class);
    $draft = $poster->postImmediateExpense($expense, $account, $this->user->id);
    $transaction = $this->service->post($draft);

    $expense->status = ExpenseStatus::Paid;
    $expense->financial_account_id = $account->id;
    $expense->paid_at = now();
    $expense->transaction_id = $transaction->id;
    $expense->save();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Paid)
        ->and($transaction->debit_account_id)->toBe($category->ledger_account_id)
        ->and($transaction->credit_account_id)->toBe(account('1010')->id);

    // Debit balance on the expense account matches the expense
    $expenseBalance = $this->service->balanceOf($category->ledger_account_id);
    expect($expenseBalance->toDecimal())->toBe('5000.00');
});

// ---------------------------------------------------------------------------
// 6. Cash register — opening float, closing, variance
// ---------------------------------------------------------------------------

it('opens and closes a cash session with a variance posting', function () {
    $register = app(CashRegisterService::class);
    $financialAccount = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
        'is_default_for_cash' => true,
    ]);

    // Open session with 50000 float
    $session = $register->openSession($financialAccount, '50000.00', $this->user);
    expect($session->status)->toBe(CashSessionStatus::Open)
        ->and((float) $session->opening_float)->toBe(50000.00);

    // Post some movements
    $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '30000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Customer payment',
        createdById: $this->user->id,
        cashSessionId: $session->id,
        branchId: $session->branch_id,
    ));

    // Expected = 50000 opening + 30000 receipts = 80000
    $expected = $register->calculateExpected($session);
    expect($expected)->toBe('80000.00');

    // Close with counted = 80500 (500 over)
    $closed = $register->closeSession($session, '80500.00', $this->user);
    expect($closed->status)->toBe(CashSessionStatus::Disputed); // variance detected

    // Cash Over entry should be posted
    $overEntry = Transaction::where('type', TransactionType::CashOver)->first();
    expect($overEntry)->not->toBeNull()
        ->and((float) $overEntry->amount)->toBe(500.00)
        ->and($overEntry->debit_account_id)->toBe(account('1010')->id)
        ->and($overEntry->credit_account_id)->toBe(account('4900')->id);

    // Balance should equal counted amount
    $balance = $register->balanceOf($financialAccount);
    expect($balance->toDecimal())->toBe('80500.00');
});

it('appends closing notes to the session when closing', function () {
    $register = app(CashRegisterService::class);
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $session = $register->openSession($account, '10000.00', $this->user);

    $closed = $register->closeSession($session, '10000.00', $this->user, 'Counted twice, short a hundred the first time.');

    expect((string) $closed->notes)->toBe('Counted twice, short a hundred the first time.');
});

it('appends closing notes to existing opening notes', function () {
    $register = app(CashRegisterService::class);
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $session = $register->openSession($account, '10000.00', $this->user);
    $session->notes = 'Float taken from the safe.';
    $session->save();

    $closed = $register->closeSession($session, '10000.00', $this->user, 'Counted twice.');

    expect((string) $closed->notes)->toBe("Float taken from the safe.\nCounted twice.");
});

it('prevents opening two concurrent sessions for the same account', function () {
    $register = app(CashRegisterService::class);
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $register->openSession($account, '10000.00', $this->user);

    expect(fn () => $register->openSession($account, '20000.00', $this->user))
        ->toThrow(RuntimeException::class, 'already exists');
});

it('computes cash balance from the ledger as sum of session movements plus float', function () {
    $register = app(CashRegisterService::class);
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $session = $register->openSession($account, '20000.00', $this->user);

    $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '15000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
        cashSessionId: $session->id,
        branchId: $session->branch_id,
    ));

    $expected = $register->calculateExpected($session);
    expect($expected)->toBe('35000.00');
});

// ---------------------------------------------------------------------------
// 7. Posting matrix — expense posting (E38, E39)
// ---------------------------------------------------------------------------

it('posts an expense with immediate payment (E39)', function () {
    $category = ExpenseCategory::where('slug', 'maintenance')->firstOrFail();
    $account = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => account('1010')->id,
        'is_active' => true,
    ]);

    $poster = app(ExpensePoster::class);
    $expense = Expense::create([
        'branch_id' => $this->branch->id,
        'expense_category_id' => $category->id,
        'amount' => 12000,
        'total_amount' => 12000,
        'incurred_on' => now(),
        'description' => 'Oil change',
        'status' => ExpenseStatus::Approved,
        'payment_method' => PaymentMethod::Cash,
    ]);

    $draft = $poster->postImmediateExpense($expense, $account, $this->user->id);
    $txn = $this->service->post($draft);

    expect($txn->debit_account_id)->toBe($category->ledger_account_id)   // Dr Maintenance & Repairs
        ->and($txn->credit_account_id)->toBe(account('1010')->id);        // Cr Main Cash Box

    // Expense balance matches
    $balance = $this->service->balanceOf($category->ledger_account_id);
    expect($balance->toDecimal())->toBe('12000.00');
});

it('posts an accrued expense (E38)', function () {
    $category = ExpenseCategory::where('slug', 'office-rent')->firstOrFail();

    $poster = app(ExpensePoster::class);
    $expense = Expense::create([
        'branch_id' => $this->branch->id,
        'expense_category_id' => $category->id,
        'amount' => 50000,
        'total_amount' => 50000,
        'incurred_on' => now(),
        'description' => 'Office rent July',
        'status' => ExpenseStatus::Approved,
    ]);

    $draft = $poster->postAccruedExpense($expense, $this->user->id);
    $txn = $this->service->post($draft);

    expect($txn->debit_account_id)->toBe($category->ledger_account_id)   // Dr Office Rent
        ->and($txn->credit_account_id)->toBe(account('2210')->id);        // Cr AP–Suppliers
});

// ---------------------------------------------------------------------------
// 8. Sequence guarantee
// ---------------------------------------------------------------------------

it('generates consecutive transaction references', function () {
    $txn1 = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '1000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    $txn2 = $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: account('4010')->id,
        amount: '2000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    expect($txn1->reference)->toMatch('/^TRX-/');
    expect($txn2->reference)->toMatch('/^TRX-/');
    expect($txn2->reference)->not->toBe($txn1->reference);
});

it('returns false from hasPostings for a new account with no transactions', function () {
    $account = ChartOfAccount::factory()->create();

    expect($account->hasPostings())->toBeFalse();
});

it('returns true from hasPostings for an account used as debit leg', function () {
    $account = account('1010');

    $this->service->post(new TransactionDraft(
        debitAccountId: $account->id,
        creditAccountId: account('4010')->id,
        amount: '1000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    expect($account->fresh()->hasPostings())->toBeTrue();
});

it('returns true from hasPostings for an account used as credit leg', function () {
    $account = account('4010');

    $this->service->post(new TransactionDraft(
        debitAccountId: account('1010')->id,
        creditAccountId: $account->id,
        amount: '1000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        createdById: $this->user->id,
    ));

    expect($account->fresh()->hasPostings())->toBeTrue();
});
