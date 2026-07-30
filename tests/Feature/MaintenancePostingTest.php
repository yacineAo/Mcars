<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CarDocumentType;
use App\Enums\CarStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarDocument;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Fleet\CompleteMaintenanceService;
use App\Services\Fleet\RecordDocumentRenewalService;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postings E41 (maintenance completed) and E42 (document renewed).
 *
 * Both existed in docs/05-accounting-model.md and neither was implemented, so a car's
 * "Expenses" and "Net Profit" on the car page omitted every repair bill and every insurance
 * premium. These tests assert both legs and the sign, per the posting-matrix convention.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    // FinancialAccountSeeder no-ops without a default branch, so it has to run after one.
    $this->seed(FinancialAccountSeeder::class);

    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->assignRole(UserRole::Manager->value);
    $this->actingAs($this->user);

    $this->car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => CarStatus::Maintenance,
        'odometer' => 40_000,
    ]);

    $this->vendor = Vendor::factory()->create(['branch_id' => $this->branch->id]);

    $this->log = MaintenanceLog::create([
        'car_id' => $this->car->id,
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'type' => MaintenanceType::OilChange,
        'status' => MaintenanceStatus::InProgress,
        'scheduled_for' => CarbonImmutable::today(),
    ]);

    $this->accountId = fn (string $code): int => (int) ChartOfAccount::where('code', $code)->value('id');

    $this->complete = fn (array $overrides = []): MaintenanceLog => app(CompleteMaintenanceService::class)->complete(
        log: $overrides['log'] ?? $this->log,
        completedAt: $overrides['completedAt'] ?? CarbonImmutable::parse('2026-07-20'),
        odometerAtService: $overrides['odometer'] ?? 45_000,
        costParts: Money::of($overrides['parts'] ?? '6000.00'),
        costLabour: Money::of($overrides['labour'] ?? '2500.00'),
        invoiceNumber: $overrides['invoice'] ?? 'INV-991',
        account: $overrides['account'] ?? null,
        userId: $this->user->id,
    );
});

// ---------------------------------------------------------------------------
// E41 — maintenance completed
// ---------------------------------------------------------------------------

it('E41 posts Dr 5040 Maintenance & Repairs against Cr 2210 AP-Suppliers when unpaid', function () {
    ($this->complete)();

    $transaction = Transaction::where('source_type', 'maintenance_log')->sole();

    expect($transaction->debit_account_id)->toBe(($this->accountId)('5040'))
        ->and($transaction->credit_account_id)->toBe(($this->accountId)('2210'))
        ->and($transaction->amount)->toBe('8500.00')
        ->and($transaction->type)->toBe(TransactionType::Maintenance)
        ->and($transaction->car_id)->toBe($this->car->id)
        ->and($transaction->branch_id)->toBe($this->branch->id)
        ->and($transaction->source_id)->toBe($this->log->id);
});

it('E41 credits the paying cash account when one is given', function () {
    $account = FinancialAccount::where('is_default_for_cash', true)->firstOrFail();

    ($this->complete)(['account' => $account]);

    $transaction = Transaction::where('source_type', 'maintenance_log')->sole();

    expect($transaction->debit_account_id)->toBe(($this->accountId)('5040'))
        ->and($transaction->credit_account_id)->toBe($account->ledger_account_id);
});

it('E41 stamps occurred_on with the completion date, not today', function () {
    ($this->complete)(['completedAt' => CarbonImmutable::parse('2026-06-03')]);

    expect(Transaction::where('source_type', 'maintenance_log')->sole()->occurred_on->format('Y-m-d'))
        ->toBe('2026-06-03');
});

it('derives total_cost from parts plus labour instead of trusting an input', function () {
    $log = ($this->complete)(['parts' => '5000.00', 'labour' => '3000.00']);

    expect($log->fresh()->total_cost)->toBe('8000.00')
        ->and(Transaction::where('source_type', 'maintenance_log')->sole()->amount)->toBe('8000.00');
});

it('posts nothing for a zero-cost service but still completes it', function () {
    $log = ($this->complete)(['parts' => '0', 'labour' => '0']);

    expect($log->fresh()->status)->toBe(MaintenanceStatus::Completed)
        ->and(Transaction::where('source_type', 'maintenance_log')->count())->toBe(0);
});

it('refuses to complete the same log twice so E41 cannot double-post', function () {
    ($this->complete)();

    expect(fn () => ($this->complete)())->toThrow(RuntimeException::class);

    expect(Transaction::where('source_type', 'maintenance_log')->count())->toBe(1);
});

it('releases the car from maintenance back to available and advances the odometer', function () {
    ($this->complete)(['odometer' => 45_000]);

    $car = $this->car->fresh();

    expect($car->status)->toBe(CarStatus::Available)
        ->and($car->odometer)->toBe(45_000);
});

it('never lets a completion lower a recorded odometer', function () {
    ($this->complete)(['odometer' => 100]);

    expect($this->car->fresh()->odometer)->toBe(40_000);
});

it('advances the matching maintenance schedule when a service completes', function () {
    $schedule = MaintenanceSchedule::create([
        'car_id' => $this->car->id,
        'task_type' => MaintenanceType::OilChange,
        'interval_km' => 10_000,
        'interval_days' => 180,
        'is_active' => true,
    ]);

    ($this->complete)(['completedAt' => CarbonImmutable::parse('2026-07-20'), 'odometer' => 45_000]);

    $schedule->refresh();

    expect($schedule->next_due_odometer)->toBe(55_000)
        ->and($schedule->next_due_at->format('Y-m-d'))->toBe('2027-01-16');
});

it('rolls back the log when the posting fails', function () {
    // 5040 missing makes the poster throw after the log has already been written, which is
    // exactly the case the transaction boundary exists for.
    ChartOfAccount::where('code', '5040')->delete();

    expect(fn () => ($this->complete)())->toThrow(RuntimeException::class);

    expect($this->log->fresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(Transaction::where('source_type', 'maintenance_log')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// E42 — document renewed
// ---------------------------------------------------------------------------

it('E42 posts insurance to Dr 5050 Insurance', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'issue_date' => CarbonImmutable::parse('2026-07-01'),
        'expiry_date' => CarbonImmutable::parse('2027-07-01'),
        'cost' => '48000.00',
    ]);

    app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id);

    $transaction = Transaction::where('source_type', 'car_document')->sole();

    expect($transaction->debit_account_id)->toBe(($this->accountId)('5050'))
        ->and($transaction->credit_account_id)->toBe(($this->accountId)('2210'))
        ->and($transaction->amount)->toBe('48000.00')
        ->and($transaction->type)->toBe(TransactionType::Insurance)
        ->and($transaction->car_id)->toBe($this->car->id)
        ->and($transaction->occurred_on->format('Y-m-d'))->toBe('2026-07-01');
});

it('E42 posts a road-tax vignette to Dr 5060 Taxes & Registration, not 5050', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::RoadTaxVignette,
        'issue_date' => CarbonImmutable::parse('2026-07-05'),
        'cost' => '3500.00',
    ]);

    app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id);

    expect(Transaction::where('source_type', 'car_document')->sole()->debit_account_id)
        ->toBe(($this->accountId)('5060'));
});

it('refuses to post the same document renewal twice', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'issue_date' => CarbonImmutable::today(),
        'cost' => '10000.00',
    ]);

    $service = app(RecordDocumentRenewalService::class);
    $service->record($document, null, $this->user->id);

    expect(fn () => $service->record($document, null, $this->user->id))->toThrow(RuntimeException::class);

    expect(Transaction::where('source_type', 'car_document')->count())->toBe(1);
});

it('refuses to post a document with no cost', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'issue_date' => CarbonImmutable::today(),
        'cost' => '0',
    ]);

    expect(fn () => app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id))
        ->toThrow(RuntimeException::class);
});

it('E42c posts a GPS subscription to Dr 5100 and still stamps the car', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::GpsSubscription,
        'issue_date' => CarbonImmutable::parse('2026-07-02'),
        'cost' => '1200.00',
    ]);

    app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id);

    $transaction = Transaction::where('source_type', 'car_document')->sole();

    // E47's 5100 row reads "branch only"; E42c is the car-dimensioned counterpart, so
    // car_id must be present or per-car profitability loses the subscription.
    expect($transaction->debit_account_id)->toBe(($this->accountId)('5100'))
        ->and($transaction->car_id)->toBe($this->car->id);
});

it('refuses to post a document with no issue date, rather than dating it today', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'issue_date' => null,
        'cost' => '30000.00',
    ]);

    expect(fn () => app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id))
        ->toThrow(RuntimeException::class);

    expect(Transaction::where('source_type', 'car_document')->count())->toBe(0);
});

it('refuses to post a document type with no account in the matrix', function () {
    $document = CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::OwnershipTitle,
        'issue_date' => CarbonImmutable::today(),
        'cost' => '2000.00',
    ]);

    expect(fn () => app(RecordDocumentRenewalService::class)->record($document, null, $this->user->id))
        ->toThrow(RuntimeException::class);

    expect(Transaction::where('source_type', 'car_document')->count())->toBe(0);
});

it('resolves "is it in the ledger" for a whole page of documents in one query', function () {
    $documents = collect(range(1, 6))->map(fn (int $i): CarDocument => CarDocument::create([
        'car_id' => $this->car->id,
        'type' => CarDocumentType::Insurance,
        'issue_date' => CarbonImmutable::parse('2026-01-01')->addDays($i),
        'cost' => '1000.00',
    ]));

    app(RecordDocumentRenewalService::class)->record($documents->first(), null, $this->user->id);

    DB::enableQueryLog();

    $rows = CarDocument::query()
        ->where('car_id', $this->car->id)
        ->withPostedToLedger()
        ->get();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One query for the page, not one per row — the column and the action's visibility both
    // read the prefetched attribute.
    expect($queries)->toBe(1)
        ->and($rows)->toHaveCount(6)
        ->and((bool) $rows->firstWhere('id', $documents->first()->id)->posted_to_ledger)->toBeTrue()
        ->and((bool) $rows->firstWhere('id', $documents->last()->id)->posted_to_ledger)->toBeFalse();
});

// ---------------------------------------------------------------------------
// The reason both postings exist
// ---------------------------------------------------------------------------

it('makes the car page expense figure include the workshop bill', function () {
    $before = app(ReportService::class)->singleCarProfitability(
        $this->car->id,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    ($this->complete)(['completedAt' => CarbonImmutable::parse('2026-07-20')]);

    $after = app(ReportService::class)->singleCarProfitability(
        $this->car->id,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    expect((float) $before['expenses'])->toBe(0.0)
        ->and((float) $after['expenses'])->toBe(8500.0)
        ->and((float) $after['net_profit'])->toBe(-8500.0);
});
