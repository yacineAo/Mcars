<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ChartOfAccountResource\Pages\ViewChartOfAccount;
use App\Filament\Admin\Resources\ChartOfAccountResource\RelationManagers\TransactionsRelationManager;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\TransactionDraft;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
});

// -----------------------------------------------------------------------
// Branch scoping on the Transactions relation manager
// -----------------------------------------------------------------------

/**
 * A chart-of-account code (e.g. 5040) is a single company-wide bucket every
 * branch posts to — not a branch-owned container the way a FinancialAccount
 * is. `reports.view_financials` (held by the accountant) does not imply
 * `branches.view_all`, so without pinning, this relation manager would show
 * every branch's postings to an accountant scoped to one branch.
 */
it('pins the account transactions relation manager to the branches the user can reach', function () {
    $mine = Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);
    $theirs = Branch::factory()->create(['code' => 'OULED']);

    $accountant = User::factory()->create(['branch_id' => $mine->id]);
    $accountant->assignRole(UserRole::Accountant->value);

    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $service = app(AccountingService::class);

    $myPosting = $service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-10'),
        description: 'My branch posting',
        branchId: $mine->id,
        createdById: $accountant->id,
    ));
    $theirPosting = $service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '200.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-11'),
        description: 'Their branch posting',
        branchId: $theirs->id,
        createdById: $accountant->id,
    ));

    Auth::login($accountant);

    Livewire::test(TransactionsRelationManager::class, [
        'ownerRecord' => $fuel,
        'pageClass' => ViewChartOfAccount::class,
    ])
        ->assertCanSeeTableRecords([$myPosting])
        ->assertCanNotSeeTableRecords([$theirPosting]);
});

/** A manager holds `branches.view_all`, so the pin is a no-op for them. */
it('shows every branch to a manager who holds branches.view_all', function () {
    $mine = Branch::factory()->create(['code' => 'MAIN', 'is_default' => true]);
    $theirs = Branch::factory()->create(['code' => 'OULED']);

    $manager = User::factory()->create(['branch_id' => $mine->id]);
    $manager->assignRole(UserRole::Manager->value);

    $fuel = ChartOfAccount::where('code', '5020')->firstOrFail();
    $rental = ChartOfAccount::where('code', '4010')->firstOrFail();

    $service = app(AccountingService::class);

    $myPosting = $service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '100.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-10'),
        description: 'My branch posting',
        branchId: $mine->id,
        createdById: $manager->id,
    ));
    $theirPosting = $service->post(new TransactionDraft(
        debitAccountId: $fuel->id,
        creditAccountId: $rental->id,
        amount: '200.00',
        type: TransactionType::Expense,
        occurredOn: new DateTimeImmutable('2026-07-11'),
        description: 'Their branch posting',
        branchId: $theirs->id,
        createdById: $manager->id,
    ));

    Auth::login($manager);

    Livewire::test(TransactionsRelationManager::class, [
        'ownerRecord' => $fuel,
        'pageClass' => ViewChartOfAccount::class,
    ])
        ->assertCanSeeTableRecords([$myPosting, $theirPosting]);
});
