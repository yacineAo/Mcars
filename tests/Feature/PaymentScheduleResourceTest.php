<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\PaymentScheduleResource;
use App\Filament\Admin\Resources\PaymentScheduleResource\Pages\EditPaymentSchedule;
use App\Filament\Admin\Resources\PaymentScheduleResource\Pages\ListPaymentSchedules;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentScheduleService;
use App\Support\Money;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function makeBooking(Branch $branch, Customer $customer, User $by): Booking
{
    return Booking::create([
        'branch_id' => $branch->id,
        'car_id' => Car::factory()->create(['branch_id' => $branch->id, 'daily_rate' => '5000.00'])->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'pickup_at' => '2026-03-01 10:00:00',
        'expected_return_at' => '2026-03-10 10:00:00',
        'daily_rate' => '5000.00',
        'days_count' => 9,
        'subtotal' => '45000.00',
        'total_amount' => '45000.00',
        'created_by_id' => $by->id,
    ]);
}

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);
    // The ledger needs its accounts: PaymentPoster resolves them by code, so a
    // payment recorded without the chart seeded fails to post.
    $this->seed(ChartOfAccountSeeder::class);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);
    Auth::login($this->accountant);
    $this->actingAs($this->accountant);

    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $this->booking = makeBooking($this->branch, $this->customer, $this->accountant);
});

it('generates an exact instalment split for a booking payment plan', function () {
    $schedules = app(PaymentScheduleService::class)->generate(
        schedulable: $this->booking,
        total: '10000.00',
        installments: 3,
        firstDueDate: Carbon::parse('2026-03-15'),
    );

    expect($schedules)->toHaveCount(3)
        ->and($schedules->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($schedules->pluck('amount')->all())->toBe(['3333.34', '3333.33', '3333.33'])
        ->and($schedules->pluck('due_date')->map->toDateString()->all())->toBe(['2026-03-15', '2026-04-15', '2026-05-15'])
        ->and($schedules->every(fn ($schedule): bool => $schedule->status === InstallmentStatus::Pending))->toBeTrue();

    $total = $this->booking->paymentSchedules()
        ->get()
        ->reduce(fn (Money $sum, $schedule): Money => $sum->plus(Money::of($schedule->amount)), Money::zero());

    expect($total->toDecimal())->toBe('10000.00');
});

it('does not generate a second plan for the same schedulable record', function () {
    $service = app(PaymentScheduleService::class);
    $service->generate($this->booking, '100.00', 3, Carbon::parse('2026-03-15'));

    expect(fn () => $service->generate($this->booking, '100.00', 3, Carbon::parse('2026-03-15')))
        ->toThrow(DomainException::class, 'already has a payment plan');

    expect($this->booking->paymentSchedules()->count())->toBe(3);
});

it('records a payment against an instalment, posts it, and allocates it to the line', function () {
    $service = app(PaymentScheduleService::class);
    $schedule = $service->generate($this->booking, '9000.00', 3, Carbon::parse('2026-03-15'))->first();

    $account = FinancialAccount::factory()->create(['branch_id' => $this->branch->id]);

    $paid = $service->recordPayment($schedule, [
        'method' => PaymentMethod::Cash->value,
        'financial_account_id' => $account->id,
    ], (int) $this->accountant->id);

    $payment = Payment::query()->sole();

    expect($paid->status)->toBe(InstallmentStatus::Paid)
        // The payment carries the instalment's amount, not the booking's total.
        ->and($payment->amount)->toBe('3000.00')
        ->and($payment->branch_id)->toBe($this->branch->id)
        // Recording and posting are one action — the ledger never disagrees.
        ->and(Transaction::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1);

    $allocation = PaymentScheduleAllocation::query()->sole();

    expect($allocation->payment_id)->toBe($payment->id)
        ->and($allocation->payment_schedule_id)->toBe($schedule->id)
        ->and($allocation->amount)->toBe('3000.00')
        ->and($allocation->branch_id)->toBe($this->branch->id);

    // The paid amount is derived from the allocations, never stored on the line.
    $derived = $schedule->paymentAllocations()
        ->get()
        ->reduce(fn (Money $sum, $row): Money => $sum->plus(Money::of($row->amount)), Money::zero());

    expect($derived->toDecimal())->toBe('3000.00');
});

it('refuses to settle or reschedule an instalment that is already paid', function () {
    $service = app(PaymentScheduleService::class);
    $schedule = $service->generate($this->booking, '100.00', 1, Carbon::parse('2026-03-15'))->sole();

    $rescheduled = $service->reschedule($schedule, Carbon::parse('2026-03-20'));
    expect($rescheduled->due_date->toDateString())->toBe('2026-03-20');

    $paid = $service->recordPayment($rescheduled, ['method' => PaymentMethod::Cash->value], (int) $this->accountant->id);
    expect($paid->status)->toBe(InstallmentStatus::Paid);

    expect(fn () => $service->reschedule($paid, Carbon::parse('2026-04-01')))
        ->toThrow(DomainException::class, 'Only an unpaid instalment');

    expect(fn () => $service->recordPayment($paid, ['method' => PaymentMethod::Cash->value], (int) $this->accountant->id))
        ->toThrow(DomainException::class, 'Only an unpaid instalment');

    // The refused second payment left nothing behind.
    expect(Payment::count())->toBe(1)
        ->and(PaymentScheduleAllocation::count())->toBe(1);
});

it('settles an instalment through the table action', function () {
    $schedule = app(PaymentScheduleService::class)
        ->generate($this->booking, '9000.00', 3, Carbon::parse('2026-03-15'))
        ->first();

    Livewire::test(ListPaymentSchedules::class)
        ->mountTableAction('mark_paid', $schedule->getKey())
        ->setTableActionData(['method' => PaymentMethod::Cash->value, 'financial_account_id' => null])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($schedule->fresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(PaymentScheduleAllocation::count())->toBe(1);
});

it('reschedules through the service when the edit form is saved', function () {
    $schedule = app(PaymentScheduleService::class)
        ->generate($this->booking, '100.00', 1, Carbon::parse('2026-03-15'))
        ->sole();

    Livewire::test(EditPaymentSchedule::class, ['record' => $schedule->getKey()])
        ->fillForm(['due_date' => '2027-01-31'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($schedule->fresh()->due_date->toDateString())->toBe('2027-01-31');
});

it('counts the whole plan even when the table is filtered', function () {
    app(PaymentScheduleService::class)->generate($this->booking, '9000.00', 3, Carbon::parse('2026-03-15'));

    $unfiltered = PaymentScheduleResource::getEloquentQuery()->get();
    $filtered = PaymentScheduleResource::getEloquentQuery()->where('sequence', 1)->get();

    // A window function would count only the rows that survived the WHERE, so the
    // first instalment would render "1 of 1" the moment any filter was applied.
    expect($unfiltered->pluck('plan_installments_count')->all())->toBe([3, 3, 3])
        ->and($filtered->sole()->getAttribute('plan_installments_count'))->toBe(3);
});

it('keys plan groups on the morph pair, not the id alone', function () {
    $group = Livewire::test(ListPaymentSchedules::class)
        ->instance()
        ->getTable()
        ->getGroups()['schedulable_id'];

    // Same id, different parent — two plans, and grouping on `schedulable_id`
    // alone filed them under one heading.
    $fromBooking = new PaymentSchedule(['schedulable_type' => Booking::class, 'schedulable_id' => 7]);
    $fromContract = new PaymentSchedule(['schedulable_type' => Contract::class, 'schedulable_id' => 7]);

    expect($group->getStringKey($fromBooking))->not->toBe($group->getStringKey($fromContract))
        ->and($group->getTitle($fromBooking))->not->toBe($group->getTitle($fromContract));
});

it('gates payment schedules behind financial-report access', function () {
    expect(PaymentScheduleResource::canAccess())->toBeTrue();

    $receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $receptionist->assignRole(UserRole::Receptionist->value);
    Auth::login($receptionist);

    expect(PaymentScheduleResource::canAccess())->toBeFalse();
});

it('hides instalments belonging to a branch the user cannot reach', function () {
    app(PaymentScheduleService::class)->generate($this->booking, '300.00', 1, Carbon::parse('2026-03-15'));

    $other = Branch::factory()->create(['code' => 'OTHER']);
    $otherCustomer = Customer::factory()->create(['branch_id' => $other->id]);
    $otherUser = User::factory()->create(['branch_id' => $other->id]);
    $otherUser->assignRole(UserRole::Accountant->value);

    $otherSchedule = app(PaymentScheduleService::class)
        ->generate(makeBooking($other, $otherCustomer, $otherUser), '300.00', 1, Carbon::parse('2026-03-15'))
        ->sole();

    // The accountant is pinned to MAIN and sees only MAIN's line.
    expect(PaymentScheduleResource::getEloquentQuery()->pluck('branch_id')->all())->toBe([$this->branch->id])
        ->and(PaymentScheduleResource::canView($otherSchedule))->toBeFalse()
        ->and(PaymentScheduleResource::canEdit($otherSchedule))->toBeFalse();
});

it('never allows an instalment row to be created or deleted by hand', function () {
    $schedule = app(PaymentScheduleService::class)
        ->generate($this->booking, '300.00', 1, Carbon::parse('2026-03-15'))
        ->sole();

    expect(PaymentScheduleResource::canCreate())->toBeFalse()
        ->and(PaymentScheduleResource::canDelete($schedule))->toBeFalse()
        ->and(PaymentScheduleResource::getPages())->not->toHaveKey('create');
});

it('renders the plan-generation worklist for an authorised user', function () {
    $this->get(PaymentScheduleResource::getUrl('index'))->assertOk();
});

it('generates a plan from the header action', function () {
    Livewire::test(ListPaymentSchedules::class)
        ->mountAction('generate_plan')
        ->setActionData([
            'schedulable_type' => Booking::class,
            'booking_id' => $this->booking->id,
            'total' => '10000.00',
            'installments' => 3,
            'first_due_date' => '2026-03-15',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(PaymentSchedule::count())->toBe(3)
        ->and(PaymentSchedule::pluck('amount')->all())->toBe(['3333.34', '3333.33', '3333.33']);
});
