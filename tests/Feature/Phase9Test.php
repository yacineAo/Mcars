<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ExportFormat;
use App\Enums\NormalBalance;
use App\Enums\ReportType;
use App\Enums\TransactionType;
use App\Jobs\ExportJob;
use App\Models\Branch;
use App\Models\CarOwner;
use App\Models\ChartOfAccount;
use App\Models\PendingExport;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
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

it('excludes inter-branch clearing account 2600 from company-wide P&L', function () {
    $clearing = ChartOfAccount::create([
        'code' => '2600',
        'name' => 'Inter-branch Clearing',
        'type' => AccountType::Asset,
        'normal_balance' => NormalBalance::Debit,
    ]);

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
