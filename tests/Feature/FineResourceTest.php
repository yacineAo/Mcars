<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\FineResource;
use App\Filament\Admin\Resources\FineResource\Pages\CreateFine;
use App\Filament\Admin\Resources\FineResource\Pages\EditFine;
use App\Filament\Admin\Resources\FineResource\Pages\ListFines;
use App\Filament\Admin\Resources\FineResource\Pages\ViewFine;
use App\Filament\Admin\Resources\FineResource\RelationManagers\TransactionsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Fine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\FineLiabilityService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeActiveBooking(Branch $branch, Customer $customer, Car $car, User $by): Booking
{
    return Booking::create([
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Active,
        'pickup_at' => '2026-06-01 10:00:00',
        'expected_return_at' => '2026-06-15 10:00:00',
        'actual_pickup_at' => '2026-06-01 10:00:00',
        'daily_rate' => '5000.00',
        'days_count' => 14,
        'subtotal' => '70000.00',
        'total_amount' => '70000.00',
        'created_by_id' => $by->id,
    ]);
}

function makeFine(array $overrides = []): Fine
{
    return Fine::create(array_merge([
        'reference' => 'FIN-'.strtoupper(Str::random(8)),
        'branch_id' => test()->branch->id,
        'car_id' => test()->car->id,
        'customer_id' => test()->customer->id,
        'booking_id' => test()->booking->id,
        'type' => FineType::Speeding,
        'authority' => 'Police',
        'notice_number' => 'N-12345',
        'violation_at' => '2026-06-10 14:00:00',
        'received_at' => '2026-06-20 09:00:00',
        'due_date' => '2026-07-10',
        'amount' => '5000.00',
        'late_penalty_amount' => '0.00',
        'total_amount' => '5000.00',
        'liability' => FineLiability::PendingReview,
        'status' => FineStatus::PendingReview,
        'created_by_id' => test()->accountant->id,
    ], $overrides));
}

function roleUser(Branch $branch, UserRole $role): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);

    $this->accountant = roleUser($this->branch, UserRole::Accountant);
    $this->manager = roleUser($this->branch, UserRole::Manager);
    $this->receptionist = roleUser($this->branch, UserRole::Receptionist);
    $this->supervisor = roleUser($this->branch, UserRole::Supervisor);
    $this->maintenance = roleUser($this->branch, UserRole::MaintenanceOfficer);

    Auth::login($this->accountant);

    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $this->car = Car::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => '5000.00']);
    $this->booking = makeActiveBooking($this->branch, $this->customer, $this->car, $this->accountant);
});

// -----------------------------------------------------------------------
// Access — reading the queue is broad, deciding who pays is not
// -----------------------------------------------------------------------

it('gates the resource on fines.view and manages liability on fines.manage', function () {
    Auth::login($this->manager);
    expect(FineResource::canAccess())->toBeTrue()
        ->and(FineResource::canCreate())->toBeTrue();

    Auth::login($this->accountant);
    expect(FineResource::canAccess())->toBeTrue()
        ->and(FineResource::canCreate())->toBeFalse();

    Auth::login($this->receptionist);
    expect(FineResource::canAccess())->toBeTrue()
        ->and(FineResource::canCreate())->toBeTrue();

    Auth::login($this->supervisor);
    expect(FineResource::canAccess())->toBeTrue()
        ->and(FineResource::canCreate())->toBeTrue();

    Auth::login($this->maintenance);
    expect(FineResource::canAccess())->toBeFalse();
});

it('refuses the assign action to a viewer without fines.manage', function () {
    $fine = makeFine();

    Auth::login($this->accountant);

    expect(FineResource::canEdit($fine))->toBeFalse();

    Livewire::test(ListFines::class)
        ->assertTableActionHidden('assign_liability', $fine)
        ->assertTableActionHidden('propose_liability', $fine)
        ->assertTableActionHidden('delete', $fine);
});

it('refuses a crafted assign call from a viewer without fines.manage', function () {
    $fine = makeFine();

    Auth::login($this->accountant);

    // A disabled action refuses to mount: the crafted call cannot even open
    // the decision modal. Whatever Filament throws for that (version-specific),
    // the call must fail and nothing may reach the ledger.
    $refused = false;

    try {
        Livewire::test(ListFines::class)
            ->callTableAction('assign_liability', $fine->getKey());
    } catch (Throwable) {
        $refused = true;
    }

    expect($refused)->toBeTrue()
        ->and(Transaction::where('source_type', 'fine')->where('source_id', $fine->id)->count())->toBe(0);
});

// -----------------------------------------------------------------------
// The suggestion — the system proposes, a human decides
// -----------------------------------------------------------------------

it('proposes the active booking as the liable party and saves the suggestion', function () {
    $fine = makeFine(['booking_id' => null, 'customer_id' => null, 'contract_id' => null]);

    app(FineLiabilityService::class)->proposeLiability($fine);

    $fresh = $fine->fresh();

    expect($fresh->booking_id)->toBe($this->booking->id)
        ->and($fresh->customer_id)->toBe($this->customer->id)
        // The liability itself is untouched: the human still decides.
        ->and($fresh->liability)->toBe(FineLiability::PendingReview);
});

it('proposes the company when no booking was active at the offence time', function () {
    $fine = makeFine([
        'booking_id' => null,
        'customer_id' => null,
        'violation_at' => '2026-05-01 14:00:00',
    ]);

    app(FineLiabilityService::class)->proposeLiability($fine);

    $fresh = $fine->fresh();

    expect($fresh->booking_id)->toBeNull()
        ->and($fresh->customer_id)->toBeNull()
        ->and($fresh->liability_note)->toContain('company');
});

it('still suggests the customer when the rental has since been completed', function () {
    // Fines arrive weeks after the rental: by then the booking is `completed`,
    // and the suggestion must still read the time window, not today's status.
    $this->booking->update(['status' => BookingStatus::Completed]);

    $fine = makeFine(['booking_id' => null, 'customer_id' => null, 'contract_id' => null]);

    app(FineLiabilityService::class)->proposeLiability($fine);

    expect($fine->fresh()->booking_id)->toBe($this->booking->id)
        ->and($fine->fresh()->customer_id)->toBe($this->customer->id);
});

it('only proposes for an unsaved fine — the suggestion never commits', function () {
    $fine = Fine::make([
        'reference' => 'FIN-UNSAVED',
        'car_id' => $this->car->id,
        'violation_at' => '2026-06-10 14:00:00',
    ]);

    $proposal = app(FineLiabilityService::class)->proposeLiability($fine);

    // The proposal names who the system thinks pays (ADR-011: hit => customer),
    // but it is only a proposal: the fine is not persisted, the status stays
    // pending_review, and nothing reaches the ledger.
    expect($proposal->liability)->toBe(FineLiability::Customer)
        ->and($proposal->status)->toBe(FineStatus::PendingReview)
        ->and($proposal->booking_id)->toBe($this->booking->id)
        ->and($proposal->exists)->toBeFalse()
        ->and(Fine::where('reference', 'FIN-UNSAVED')->doesntExist())->toBeTrue()
        ->and(Transaction::query()->where('source_type', 'fine')->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Deciding posts E49 or E50 in the same transaction
// -----------------------------------------------------------------------

it('assigning to the customer posts E49 — receivable, profit untouched', function () {
    $fine = makeFine();

    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Customer, (int) $this->accountant->id);

    $posting = Transaction::query()
        ->where('source_type', 'fine')
        ->where('source_id', $fine->id)
        ->sole();

    $fresh = $fine->fresh();

    expect($posting->debitAccount->code)->toBe('1120')
        ->and($posting->creditAccount->code)->toBe('2220')
        ->and($posting->type)->toBe(TransactionType::FineReceived)
        ->and($fresh->status)->toBe(FineStatus::AssignedToCustomer)
        ->and($fresh->liability_determined_by_id)->toBe($this->accountant->id)
        ->and($fresh->liability_determined_at)->not->toBeNull();
});

it('assigning to the company posts E50 — an absorbed expense', function () {
    $fine = makeFine();

    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Company, (int) $this->accountant->id);

    $posting = Transaction::query()
        ->where('source_type', 'fine')
        ->where('source_id', $fine->id)
        ->sole();

    expect($posting->debitAccount->code)->toBe('5140')
        ->and($posting->creditAccount->code)->toBe('2220')
        ->and($fine->fresh()->status)->toBe(FineStatus::PaidByCompany);
});

it('decides through the row action, posting exactly one matrix row', function () {
    $fine = makeFine();

    Auth::login($this->manager);

    Livewire::test(ListFines::class)
        ->mountTableAction('assign_liability', $fine->getKey())
        ->setTableActionData(['liability' => 'customer'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Transaction::where('source_type', 'fine')->where('source_id', $fine->id)->count())->toBe(1)
        ->and($fine->fresh()->status)->toBe(FineStatus::AssignedToCustomer);
});

it('refuses to decide twice — the service says no and nothing double-posts', function () {
    $fine = makeFine();

    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Customer, (int) $this->accountant->id);

    expect(fn () => app(FineLiabilityService::class)
        ->confirmLiability($fine, FineLiability::Company, (int) $this->manager->id))
        ->toThrow(DomainException::class, 'already posted');

    expect(Transaction::where('source_type', 'fine')->where('source_id', $fine->id)->count())->toBe(1);
});

// -----------------------------------------------------------------------
// Freeze — a decided fine is read-only
// -----------------------------------------------------------------------

it('freezes the money, liability and customer once posted', function () {
    $fine = makeFine();

    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Customer, (int) $this->accountant->id);

    Auth::login($this->manager);

    // The freeze is enforced at the page gate: a posted fine cannot be opened
    // for editing at all, and the only way back is a reversal.
    expect(FineResource::canEdit($fine))->toBeFalse();

    $this->get(FineResource::getUrl('edit', ['record' => $fine]))->assertForbidden();
});

// -----------------------------------------------------------------------
// Delete — a single unposted row only, never bulk
// -----------------------------------------------------------------------

it('offers no bulk delete; a single delete survives only while unposted', function () {
    $unposted = makeFine();
    $posted = makeFine(['reference' => 'FIN-POSTED']);

    app(FineLiabilityService::class)->confirmLiability($posted, FineLiability::Company, (int) $this->accountant->id);

    Auth::login($this->manager);

    expect(FineResource::canDelete($unposted))->toBeTrue()
        ->and(FineResource::canDelete($posted))->toBeFalse();

    // The posted fine is also gone from the undecided queue (the default
    // view), so only the unposted row still carries its actions.
    Livewire::test(ListFines::class)
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('deleteMany');

    expect(Livewire::test(ListFines::class)->instance()->getTable()->getBulkActions())->toBeEmpty();

    Livewire::test(ListFines::class)
        ->callTableAction('delete', $unposted)
        ->assertHasNoTableActionErrors();

    expect(Fine::find($unposted->id))->toBeNull()
        ->and(Fine::find($posted->id))->not->toBeNull();
});

// -----------------------------------------------------------------------
// Create — the tedious part is a suggestion, the total is composed
// -----------------------------------------------------------------------

it('pre-fills booking and customer from car and offence time', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateFine::class)
        ->set('data.car_id', $this->car->id)
        ->set('data.violation_at', '2026-06-10 14:00:00')
        ->assertFormSet([
            'booking_id' => $this->booking->id,
            'customer_id' => $this->customer->id,
        ]);
});

it('composes the total from amount and late penalty, never typed', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateFine::class)
        ->set('data.amount', '5000.00')
        ->set('data.late_penalty_amount', '500.00')
        ->assertFormSet(['total_amount' => '5500.00']);
});

it('saves through the create page with the composed total and an undecided liability', function () {
    Auth::login($this->receptionist);

    Livewire::test(CreateFine::class)
        ->set('data.reference', 'FIN-UI-1')
        ->set('data.car_id', $this->car->id)
        ->set('data.type', FineType::Speeding->value)
        ->set('data.violation_at', '2026-06-10 14:00:00')
        ->set('data.received_at', '2026-06-20 09:00:00')
        ->set('data.amount', '5000.00')
        ->set('data.late_penalty_amount', '500.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $fine = Fine::where('reference', 'FIN-UI-1')->first();

    expect($fine)->not->toBeNull()
        ->and($fine->total_amount)->toBe('5500.00')
        // The decision is the action's, never the form's: a freshly entered
        // fine is undecided even if the payload tried to claim otherwise.
        ->and($fine->liability)->toBe(FineLiability::PendingReview)
        ->and($fine->status)->toBe(FineStatus::PendingReview);
});

it('recomposes and persists the total when an unposted fine is edited', function () {
    $fine = makeFine(['late_penalty_amount' => '0.00']);

    Auth::login($this->manager);

    Livewire::test(EditFine::class, ['record' => $fine->getKey()])
        ->set('data.amount', '8000.00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fine->fresh()->total_amount)->toBe('8000.00')
        ->and($fine->fresh()->liability)->toBe(FineLiability::PendingReview)
        ->and($fine->fresh()->status)->toBe(FineStatus::PendingReview);
});

it('posts the composed total when liability is decided', function () {
    $fine = makeFine(['late_penalty_amount' => '500.00', 'total_amount' => '5500.00']);

    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Customer, (int) $this->accountant->id);

    $posting = Transaction::query()
        ->where('source_type', 'fine')
        ->where('source_id', $fine->id)
        ->sole();

    expect($posting->amount)->toBe('5500.00');
});

// -----------------------------------------------------------------------
// Index — the undecided queue comes first
// -----------------------------------------------------------------------

it('defaults the index to undecided fines', function () {
    $decided = makeFine(['reference' => 'FIN-DECIDED']);
    app(FineLiabilityService::class)->confirmLiability($decided, FineLiability::Customer, (int) $this->accountant->id);

    $undecided = makeFine(['reference' => 'FIN-UNDECIDED']);

    Auth::login($this->receptionist);

    Livewire::test(ListFines::class)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$undecided])
        ->assertCanNotSeeTableRecords([$decided]);
});

it('filters by type, car and customer', function () {
    makeFine(['type' => FineType::Parking]);
    makeFine(['type' => FineType::Speeding]);

    Auth::login($this->receptionist);

    Livewire::test(ListFines::class)
        ->filterTable('type', FineType::Parking->value)
        ->assertCountTableRecords(1)
        ->filterTable('car_id', $this->car->id)
        ->assertCountTableRecords(1);
});

// -----------------------------------------------------------------------
// View — the full record plus a gated postings table
// -----------------------------------------------------------------------

it('renders the view page with the decision trail and the posting', function () {
    $fine = makeFine();
    app(FineLiabilityService::class)->confirmLiability($fine, FineLiability::Customer, (int) $this->accountant->id);

    Auth::login($this->manager);

    $this->get(FineResource::getUrl('view', ['record' => $fine]))
        ->assertOk()
        ->assertSee('N-12345')
        ->assertSee($this->car->registration_number);
});

it('shows the postings only to a financial reader', function () {
    $fine = makeFine();

    Auth::login($this->accountant);
    expect(TransactionsRelationManager::canViewForRecord($fine, ViewFine::class))->toBeTrue();

    Auth::login($this->receptionist);
    expect(TransactionsRelationManager::canViewForRecord($fine, ViewFine::class))->toBeFalse();
});

// -----------------------------------------------------------------------
// Branch scoping — a fine is a financial record, pinned server-side
// -----------------------------------------------------------------------

it('pins fines to the branches the user can reach', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);
    $otherCustomer = Customer::factory()->create(['branch_id' => $other->id]);
    $otherCar = Car::factory()->create(['branch_id' => $other->id, 'daily_rate' => '5000.00']);

    makeFine(['reference' => 'FIN-MAIN', 'booking_id' => null]);

    $elsewhere = makeFine([
        'reference' => 'FIN-ORAN',
        'branch_id' => $other->id,
        'car_id' => $otherCar->id,
        'customer_id' => $otherCustomer->id,
        'booking_id' => null,
    ]);

    Auth::login($this->receptionist);

    expect($this->receptionist->can('branches.view_all'))->toBeFalse();

    Livewire::test(ListFines::class)
        ->assertCountTableRecords(1)
        ->assertCanNotSeeTableRecords([$elsewhere]);

    expect(FineResource::canView($elsewhere))->toBeFalse()
        ->and(FineResource::canEdit($elsewhere))->toBeFalse();

    $this->get(FineResource::getUrl('view', ['record' => $elsewhere]))->assertNotFound();
});

it('refuses a cross-branch delete even through a crafted call', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);
    $otherCar = Car::factory()->create(['branch_id' => $other->id, 'daily_rate' => '5000.00']);

    $elsewhere = makeFine([
        'reference' => 'FIN-ORAN',
        'branch_id' => $other->id,
        'car_id' => $otherCar->id,
        'booking_id' => null,
    ]);

    // The receptionist holds fines.manage but not branches.view_all: the
    // record is absent from the pinned table, so the action cannot even
    // mount; `visible()` and `disabled()` are the second and third lines.
    Auth::login($this->receptionist);

    expect(fn () => Livewire::test(ListFines::class)
        ->callTableAction('delete', $elsewhere),
    )->toThrow(ActionNotResolvableException::class);

    expect(Fine::find($elsewhere->id))->not->toBeNull();
});
