<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\ConditionReportType;
use App\Enums\ContractStatus;
use App\Enums\FuelLevel;
use App\Enums\SignatureMethod;
use App\Enums\SignerRole;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ContractResource;
use App\Filament\Admin\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Admin\Resources\ContractResource\Pages\EditContract;
use App\Filament\Admin\Resources\ContractResource\Pages\ListContracts;
use App\Filament\Admin\Resources\ContractResource\Pages\ViewContract;
use App\Filament\Admin\Resources\ContractResource\RelationManagers\DepositsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\ContractService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ChartOfAccountSeeder::class);
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->seed(RolePermissionSeeder::class);

    foreach ([
        'admin' => UserRole::SuperAdmin,
        'manager' => UserRole::Manager,
        'receptionist' => UserRole::Receptionist,
        'supervisor' => UserRole::Supervisor,
        'accountant' => UserRole::Accountant,
        'maintenance' => UserRole::MaintenanceOfficer,
    ] as $name => $role) {
        $this->{$name} = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->{$name}->assignRole($role->value);
    }

    $this->car = Car::factory()->create([
        'status' => CarStatus::Available,
        'daily_rate' => '5000.00',
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
});

function bookingForContractTest(): Booking
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();

    return Booking::create([
        'branch_id' => $branch->id,
        'car_id' => Car::factory()->create([
            'status' => CarStatus::Available,
            'daily_rate' => '5000.00',
            'branch_id' => $branch->id,
        ])->id,
        'customer_id' => Customer::firstOrFail()->id,
        'created_by_id' => User::firstOrFail()->id,
        'status' => BookingStatus::Draft,
        'pickup_at' => now()->addDay(),
        'expected_return_at' => now()->addDays(5),
        'daily_rate' => '5000.00',
        'days_count' => 5,
        'subtotal' => '25000.00',
        'extras_total' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => '25000.00',
        'security_deposit_amount' => '30000.00',
    ]);
}

function contractForTest(ContractService $service, Booking $booking): Contract
{
    return $service->generate($booking, null);
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('gates the whole resource behind bookings.view', function () {
    $this->actingAs($this->accountant)
        ->get(ContractResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();

    $this->actingAs($this->maintenance)
        ->get(ContractResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('gives signing its own permission', function () {
    $this->actingAs($this->receptionist);
    expect(ContractResource::canSign())->toBeTrue();

    $this->actingAs($this->accountant);
    expect(ContractResource::canSign())->toBeFalse();
});

it('hides the sign action without contracts.sign', function () {
    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    $contract->update(['status' => ContractStatus::AwaitingSignature]);

    Livewire::actingAs($this->accountant)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('sign');

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('sign');
});

it('lets only a manager delete, and only a draft', function () {
    $service = app(ContractService::class);
    $draft = contractForTest($service, bookingForContractTest());
    $signed = contractForTest($service, bookingForContractTest());
    $signed->update(['status' => ContractStatus::Signed]);

    Livewire::actingAs($this->accountant)
        ->test(ListContracts::class)
        ->filterTable('status', null)
        ->assertTableActionHidden('delete', $draft);

    Livewire::actingAs($this->manager)
        ->test(ListContracts::class)
        ->filterTable('status', null)
        ->assertTableActionVisible('delete', $draft)
        ->assertTableActionHidden('delete', $signed);
});

// ---------------------------------------------------------------------------
// The lifecycle goes through the service
// ---------------------------------------------------------------------------

it('creates the contract by generating it from the booking, not by typing it', function () {
    $booking = bookingForContractTest();

    Livewire::actingAs($this->receptionist)
        ->test(CreateContract::class)
        ->fillForm([
            'booking_id' => $booking->id,
            'franchise_amount' => '5000.00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contract = $booking->fresh()->contract;

    expect($contract)->not->toBeNull()
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->content_snapshot)->not->toBeNull()
        ->and($contract->content_snapshot['booking_reference'])->toBe($booking->reference)
        ->and($contract->content_snapshot['locale'])->toBe('fr')
        ->and($contract->contract_number)->not->toBeNull()
        ->and($contract->franchise_amount)->toBe('5000.00');

    // A booking cannot hold two contracts — the service throws, and the create form
    // only offers bookings without one.
    expect(fn () => app(ContractService::class)->generate($booking, null))
        ->toThrow(RuntimeException::class);
});

it('signs through the service: writes the signature row and freezes the contract', function () {
    $contract = contractForTest(app(ContractService::class), bookingForContractTest());

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('sign', [
            'signer_role' => 'customer',
            'signer_name' => 'Yacine Test',
        ]);

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Signed)
        ->and($contract->signed_at)->not->toBeNull();

    $signature = ContractSignature::query()->where('contract_id', $contract->id)->first();

    expect($signature)->not->toBeNull()
        ->and($signature->signer_role->value)->toBe('customer')
        ->and($signature->signer_name_snapshot)->toBe('Yacine Test')
        ->and($signature->method)->toBe(SignatureMethod::InPersonPaper)
        // The witness is recorded — the audit trail answers who vouched, not just
        // whose signature went on the document.
        ->and($signature->signed_by_id)->toBe($this->receptionist->id)
        ->and($signature->signed_at)->not->toBeNull();

    // Frozen after signing: every term field is disabled, only closing notes stay open.
    Livewire::actingAs($this->receptionist)
        ->test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormFieldDisabled('franchise_amount')
        ->assertFormFieldDisabled('contract_template_id')
        ->assertFormFieldEnabled('closing_notes');

    // The service itself refuses a second signature.
    expect(fn () => app(ContractService::class)->markSigned($contract, SignerRole::Customer, 'X', $this->receptionist))
        ->toThrow(RuntimeException::class);
});

it('closes through the service, taking the check-in report as the source of truth', function () {
    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    $contract->update(['status' => ContractStatus::Active]);

    $report = ConditionReport::create([
        'booking_id' => $contract->booking_id,
        'type' => ConditionReportType::Checkin,
        'performed_at' => now(),
        'performed_by_id' => $this->receptionist->id,
        'odometer' => 50000,
        'fuel_level' => FuelLevel::Full,
        'is_clean' => false,
        'damage_points' => [['description' => 'Scratched door']],
        'notes' => 'Scratch on the driver door.',
    ]);

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('close', [
            'checkin_report' => $report->id,
        ]);

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Closed)
        ->and($contract->closed_by_id)->toBe($this->receptionist->id)
        ->and($contract->closing_notes)->toBe('Scratch on the driver door.')
        ->and($contract->has_damages)->toBeTrue();
});

it('refuses to close with a check-in report from another booking', function () {
    $service = app(ContractService::class);
    $contract = contractForTest($service, bookingForContractTest());
    $contract->update(['status' => ContractStatus::Active]);

    $other = contractForTest($service, bookingForContractTest());
    $report = ConditionReport::create([
        'booking_id' => $other->booking_id,
        'type' => ConditionReportType::Checkin,
        'performed_at' => now(),
        'performed_by_id' => $this->receptionist->id,
        'odometer' => 50000,
        'fuel_level' => FuelLevel::Full,
        'is_clean' => true,
        'notes' => 'Clean.',
    ]);

    expect(fn () => $service->close($contract, $report, $this->receptionist))
        ->toThrow(RuntimeException::class)
        ->and($contract->fresh()->status)->toBe(ContractStatus::Active);
});

it('renders the PDF through the view page header action', function () {
    Storage::fake('private');

    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    $contract->update(['status' => ContractStatus::AwaitingSignature]);

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('render_pdf');

    $path = 'contracts/'.$contract->contract_number.'.pdf';
    Storage::disk('private')->assertExists($path);
    expect(Storage::disk('private')->get($path))->toStartWith('%PDF-');
});

it('offers a download_pdf action only once a PDF has been generated, and it resolves to a real PDF', function () {
    Storage::fake('private');

    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    $contract->update(['status' => ContractStatus::AwaitingSignature]);

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('download_pdf');

    app(ContractService::class)->renderPdf($contract);

    Livewire::actingAs($this->receptionist)
        ->test(ViewContract::class, ['record' => $contract->fresh()->getRouteKey()])
        ->assertActionVisible('download_pdf');

    $response = $this->actingAs($this->receptionist)->get(
        URL::temporarySignedRoute('contracts.pdf.download', now()->addMinutes(5), ['contract' => $contract->id]),
    );

    // Storage::disk()->download() returns a BinaryFileResponse, whose getContent()
    // is `false` by design (it streams the file rather than buffering it) — assert
    // on the response instead of trying to read the body through the test client.
    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition');
    expect($response->headers->get('Content-Disposition'))->toContain($contract->contract_number.'.pdf');
});

it('refuses an unsigned or expired link to the contract PDF', function () {
    Storage::fake('private');

    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    app(ContractService::class)->renderPdf($contract);

    $this->actingAs($this->receptionist)
        ->get(route('contracts.pdf.download', ['contract' => $contract->id]))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// The index list and the view page
// ---------------------------------------------------------------------------

it('defaults the status filter to awaiting signature', function () {
    $service = app(ContractService::class);
    $awaiting = contractForTest($service, bookingForContractTest());
    $awaiting->update(['status' => ContractStatus::AwaitingSignature]);
    $closed = contractForTest($service, bookingForContractTest());
    $closed->update(['status' => ContractStatus::Closed]);

    Livewire::actingAs($this->receptionist)
        ->test(ListContracts::class)
        ->assertCanSeeTableRecords([$awaiting])
        ->assertCanNotSeeTableRecords([$closed]);
});

it('filters by the generated date range', function () {
    $service = app(ContractService::class);
    $now = contractForTest($service, bookingForContractTest());
    $old = contractForTest($service, bookingForContractTest());
    $old->update(['generated_at' => now()->subMonths(2)]);

    Livewire::actingAs($this->receptionist)
        ->test(ListContracts::class)
        ->filterTable('status', null)
        ->filterTable('generated_between', [
            'generated_from' => now()->toDateString(),
            'generated_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$now])
        ->assertCanNotSeeTableRecords([$old]);
});

it('renders the view page with the sanitised document in the snapshot locale', function () {
    $contract = contractForTest(app(ContractService::class), bookingForContractTest());
    $contract->update([
        'content_snapshot' => array_merge($contract->content_snapshot, [
            'locale' => 'ar',
            'template_body' => '<script>alert(1)</script><p onclick="alert(1)">Ok</p>',
        ]),
    ]);

    $this->actingAs($this->receptionist)
        ->get(ContractResource::getUrl('view', ['record' => $contract], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('dir="rtl"', false)
        ->assertSee('<p>Ok</p>', false)
        ->assertDontSee('alert(1)')
        ->assertDontSee('onclick');
});

it('gates the deposits relation manager behind reports.view_financials', function () {
    $contract = contractForTest(app(ContractService::class), bookingForContractTest());

    $this->actingAs($this->accountant);
    expect(DepositsRelationManager::canViewForRecord($contract, ViewContract::class))->toBeTrue();

    $this->actingAs($this->receptionist);
    expect(DepositsRelationManager::canViewForRecord($contract, ViewContract::class))->toBeFalse();
});
