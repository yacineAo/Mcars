<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Enums\TransactionType;
use App\Jobs\ExportJob;
use App\Models\Branch;
use App\Models\CarOwner;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\PendingExport;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use App\Services\CashRegisterService;
use App\Services\Reporting\ReportDataResolver;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->assignRole('manager');

    $this->reportService = app(ReportService::class);
    $this->accounting = app(AccountingService::class);
});

it('creates a pending export and dispatches the job', function () {
    Queue::fake();

    $export = PendingExport::create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->user->id,
        'report_type' => ReportType::ProfitAndLoss->value,
        'format' => ExportFormat::Pdf->value,
        'parameters' => [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ],
        'status' => 'pending',
    ]);

    expect($export->id)->toBeGreaterThan(0);
    expect($export->isPending())->toBeTrue();
    expect($export->report_type)->toBeInstanceOf(ReportType::class);
    expect($export->format)->toBeInstanceOf(ExportFormat::class);

    ExportJob::dispatch($export, $this->user->id);

    Queue::assertPushed(ExportJob::class);
});

it('marks export as processing and completed through lifecycle', function () {
    $export = PendingExport::factory()->create([
        'user_id' => $this->user->id,
        'branch_id' => $this->branch->id,
        'status' => 'pending',
    ]);

    expect($export->isPending())->toBeTrue();

    $export->markAsProcessing();
    expect($export->fresh()->isProcessing())->toBeTrue();

    $export->markAsCompleted('exports/test.pdf', 'test.pdf', 1024);
    expect($export->fresh()->isCompleted())->toBeTrue();
    expect($export->fresh()->file_size)->toBe(1024);

    $export->markAsDownloaded();
    expect($export->fresh()->downloaded_at)->not->toBeNull();
});

it('marks export as failed with error message', function () {
    $export = PendingExport::factory()->create([
        'user_id' => $this->user->id,
        'branch_id' => $this->branch->id,
        'status' => 'processing',
    ]);

    $export->markAsFailed('Something went wrong');

    expect($export->fresh()->isFailed())->toBeTrue();
    expect($export->fresh()->error_message)->toBe('Something went wrong');
});

it('generates correct download URL for completed exports', function () {
    Storage::fake('private');

    $export = PendingExport::factory()->create([
        'user_id' => $this->user->id,
        'branch_id' => $this->branch->id,
        'status' => 'completed',
        'file_path' => 'exports/test_report.pdf',
        'file_name' => 'test_report.pdf',
    ]);

    expect($export->isCompleted())->toBeTrue();
    expect($export->downloadUrl())->not->toBeNull();
});

it('computes owner statement from the ledger', function () {
    $owner = CarOwner::factory()->create([
        'branch_id' => $this->branch->id,
        'first_name' => 'Ahmed',
        'last_name' => 'Benali',
    ]);

    // Post a credit to account 2200 (AP-Car Owners) to simulate owed rent
    $ownerPayable = ChartOfAccount::where('code', '2200')->first();
    $expenseAccount = ChartOfAccount::where('code', '5010')->first();

    $this->accounting->post(new TransactionDraft(
        debitAccountId: $expenseAccount->id,
        creditAccountId: $ownerPayable->id,
        amount: '45000.00',
        type: TransactionType::OwnerInstallment,
        occurredOn: new DateTimeImmutable('2026-01-15'),
        branchId: $this->branch->id,
        carOwnerId: $owner->id,
    ));

    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31');

    $result = $this->reportService->ownerStatement($owner->id, $from, $to, $this->branch->id);

    expect($result['owner_name'])->toBe('Ahmed Benali');
    expect($result)->toHaveKeys(['installments', 'total_due', 'total_paid', 'balance']);
    expect($result['balance'])->toBe(45000.0);
});

it('computes cash session audit', function () {
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31');

    $result = $this->reportService->cashSessionAudit($from, $to, $this->branch->id);

    expect($result)->toBeArray();
});

it('does not double count the opening float in the cash session audit', function () {
    $register = app(CashRegisterService::class);
    $financialAccount = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'ledger_account_id' => ChartOfAccount::where('code', '1010')->value('id'),
        'is_active' => true,
    ]);

    $session = $register->openSession($financialAccount, '50000.00', $this->user);

    $this->accounting->post(new TransactionDraft(
        debitAccountId: ChartOfAccount::where('code', '1010')->value('id'),
        creditAccountId: ChartOfAccount::where('code', '4010')->value('id'),
        amount: '30000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable,
        description: 'Customer payment',
        createdById: $this->user->id,
        cashSessionId: $session->id,
        branchId: $session->branch_id,
    ));

    $register->closeSession($session, '80000.00', $this->user);

    $from = CarbonImmutable::now()->startOfDay();
    $to = CarbonImmutable::now()->endOfDay();

    $result = $this->reportService->cashSessionAudit($from, $to, $this->branch->id);

    expect($result)->toHaveCount(1);
    // Expected must equal what the close computed (float 50000 + 30000 receipts),
    // not float + net-with-float (which would double count to 130000). Money
    // leaves the report as fixed-point strings, never floats.
    expect($result[0]['opening_float'])->toBe('50000.00');
    expect($result[0]['expected'])->toBe('80000.00');
    expect($result[0]['counted'])->toBe('80000.00');
    expect($result[0]['variance'])->toBe('0.00');
});

it('excludes inter-branch clearing account 2600 from company-wide P&L', function () {
    $clearing = ChartOfAccount::where('code', '2600')->first();
    expect($clearing)->not->toBeNull();

    $clearingId = $this->reportService->interBranchClearingAccountId();
    expect($clearingId)->not->toBeNull();

    $cashAccount = ChartOfAccount::where('code', '1010')->first();
    $revenueAccount = ChartOfAccount::where('code', '4010')->first();

    // Normal revenue transaction — should appear in both scopes
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $cashAccount->id,
        creditAccountId: $revenueAccount->id,
        amount: '50000.00',
        type: TransactionType::RentalRevenue,
        occurredOn: new DateTimeImmutable('2026-01-15'),
        branchId: $this->branch->id,
    ));

    // Internal clearing transaction — should be excluded company-wide
    $this->accounting->post(new TransactionDraft(
        debitAccountId: $cashAccount->id,
        creditAccountId: $clearing->id,
        amount: '10000.00',
        type: TransactionType::CashTransfer,
        occurredOn: new DateTimeImmutable('2026-01-15'),
        branchId: $this->branch->id,
    ));

    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31');

    // Company-wide P&L sees only the revenue transaction
    $companyPnl = $this->reportService->profitAndLoss($from, $to);
    expect($companyPnl['revenue'])->toBe(50000.0);
    expect($companyPnl['net_profit'])->toBe(50000.0);

    // Per-branch P&L includes both (clearing is not filtered)
    $branchPnl = $this->reportService->profitAndLoss($from, $to, $this->branch->id);
    expect($branchPnl['revenue'])->toBe(50000.0);
});

it('returns report type enum labels and options for the form', function () {
    $options = ReportType::options();

    expect($options)->toHaveKey('profit_and_loss');
    expect($options)->toHaveKey('expense_breakdown');
    expect($options)->toHaveKey('customer_report');
    expect($options)->toHaveKey('fleet_profitability');
    expect($options)->toHaveKey('cash_flow');
    expect($options)->toHaveKey('owner_statement');
    expect($options)->toHaveKey('receivables_ageing');
    expect($options)->toHaveKey('cash_session_audit');
});

it('returns export format mime types and extensions', function () {
    expect(ExportFormat::Pdf->mimeType())->toBe('application/pdf');
    expect(ExportFormat::Xlsx->mimeType())->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(ExportFormat::Csv->mimeType())->toBe('text/csv');

    expect(ExportFormat::Pdf->extension())->toBe('pdf');
    expect(ExportFormat::Xlsx->extension())->toBe('xlsx');
    expect(ExportFormat::Csv->extension())->toBe('csv');
});

/**
 * A three-year export is the archive worst case: it must complete on the queue,
 * not stall the worker. The job is executed synchronously here — a queue worker
 * runs exactly the same handle() — and the wall-clock bound keeps a regression
 * that turns the export into a minutes-long scan loud instead of silent.
 *
 * Marked group "slow" so it can be run in isolation (it seeds three years of
 * ledger activity); it still runs in the default suite, at a few seconds' cost.
 *
 *     ./vendor/bin/pest tests/Feature/Phase9Test.php --group=slow
 */
it('completes a three-year export without timing out', function () {
    Storage::fake('private');

    $cash = ChartOfAccount::where('code', '1010')->firstOrFail();
    $expense = ChartOfAccount::where('code', '5010')->firstOrFail();

    // Three years of monthly activity, so the period scan is the worst one.
    $month = CarbonImmutable::parse('2024-01-15');

    for ($i = 0; $i < 36; $i++) {
        $this->accounting->post(new TransactionDraft(
            debitAccountId: $expense->id,
            creditAccountId: $cash->id,
            amount: '1000.00',
            type: TransactionType::Expense,
            occurredOn: new DateTimeImmutable($month->toDateString()),
            branchId: $this->branch->id,
        ));

        $month = $month->addMonth();
    }

    $export = PendingExport::factory()->create([
        'user_id' => $this->user->id,
        'branch_id' => $this->branch->id,
        'report_type' => ReportType::ExpenseBreakdown->value,
        'format' => ExportFormat::Csv->value,
        'parameters' => [
            'branch_id' => $this->branch->id,
            'from' => '2024-01-01',
            'to' => '2026-12-31',
        ],
        'status' => 'pending',
    ]);

    $startedAt = microtime(true);

    (new ExportJob($export, $this->user->id))->handle(
        app(ReportService::class),
        app(ReportDataResolver::class),
    );

    expect($export->refresh()->isCompleted())->toBeTrue()
        ->and($export->file_size)->toBeGreaterThan(0)
        // The queue worker's timeout is 600s; if the export approaches that
        // this test catches it before it ever lands on the queue.
        ->and(microtime(true) - $startedAt)->toBeLessThan(120);
})->group('slow');
