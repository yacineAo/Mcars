<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ContractStatus;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ContractTemplateResource;
use App\Filament\Admin\Resources\ContractTemplateResource\Pages\CreateContractTemplate;
use App\Filament\Admin\Resources\ContractTemplateResource\Pages\EditContractTemplate;
use App\Filament\Admin\Resources\ContractTemplateResource\Pages\ListContractTemplates;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\ContractService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->assignRole(UserRole::Accountant->value);

    $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->supervisor->assignRole(UserRole::Supervisor->value);

    $this->maintenance = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->maintenance->assignRole(UserRole::MaintenanceOfficer->value);
});

function contractTemplateForTest(array $overrides = []): ContractTemplate
{
    return ContractTemplate::create(array_merge([
        'branch_id' => Branch::where('code', 'MAIN')->valueOrFail('id'),
        'name' => 'Rental agreement',
        'locale' => 'fr',
        'body' => "Contrat\n\n{{customer_name}} — {{car_description}}\nTotal: {{total_amount}} DZD",
        'terms_version' => '1.0',
        'is_active' => true,
        'is_default' => false,
    ], $overrides));
}

/**
 * A template available to every branch. `BelongsToBranch` fills `branch_id` on create
 * whatever you pass it, so going global means clearing it afterwards — which is also
 * the only way these rows arrive today (seed or import, never the form).
 */
function globalContractTemplateForTest(array $overrides = []): ContractTemplate
{
    $template = contractTemplateForTest($overrides);

    DB::table('contract_templates')->where('id', $template->id)->update(['branch_id' => null]);

    return $template->fresh();
}

function contractRenderedFromTemplate(ContractTemplate $template): Contract
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $car = Car::factory()->create(['branch_id' => $branch->id, 'daily_rate' => '5000.00']);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $user = User::firstOrFail();

    $booking = Booking::create([
        'uuid' => (string) Str::uuid(),
        'reference' => 'BK-CT-'.Str::upper(Str::random(6)),
        'branch_id' => $branch->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'created_by_id' => $user->id,
        'status' => BookingStatus::Active,
        'pickup_at' => now()->subDays(1),
        'expected_return_at' => now()->addDays(3),
        'daily_rate' => '5000.00',
        'days_count' => 4,
        'subtotal' => '20000.00',
        'total_amount' => '20000.00',
    ]);

    return Contract::create([
        'uuid' => (string) Str::uuid(),
        'contract_number' => 'CTR-'.Str::upper(Str::random(6)),
        'branch_id' => $branch->id,
        'booking_id' => $booking->id,
        'car_id' => $car->id,
        'customer_id' => $customer->id,
        'contract_template_id' => $template->id,
        'status' => ContractStatus::Active,
        'content_snapshot' => ['template_body' => $template->body, 'rendered' => true],
        'terms_version' => $template->terms_version,
        'generated_at' => now(),
        'has_damages' => false,
    ]);
}

// -----------------------------------------------------------------------
// Access — bookings.view reads the terms, bookings.manage rewrites them.
// The split matches the rest of the bookings catalogue (see RolePermissionSeeder):
// a receptionist explaining the terms to a customer must be able to read them.
// -----------------------------------------------------------------------

it('lets the manager both read and rewrite', function () {
    Auth::login($this->manager);

    expect(ContractTemplateResource::canAccess())->toBeTrue()
        ->and(ContractTemplateResource::canCreate())->toBeTrue()
        ->and(ContractTemplateResource::canEdit(contractTemplateForTest()))->toBeTrue();

    $this->get(ContractTemplateResource::getUrl('index'))->assertOk();
    $this->get(ContractTemplateResource::getUrl('create'))->assertOk();
});

it('lets the super admin in', function () {
    Auth::login($this->admin);

    expect(ContractTemplateResource::canAccess())->toBeTrue()
        ->and(ContractTemplateResource::canManage())->toBeTrue();

    $this->get(ContractTemplateResource::getUrl('index'))->assertOk();
});

it('lets read-only staff read the terms but not rewrite them', function (string $role) {
    Auth::login($this->{$role});

    $template = contractTemplateForTest();

    expect(ContractTemplateResource::canAccess())->toBeTrue()
        ->and(ContractTemplateResource::canManage())->toBeFalse()
        ->and(ContractTemplateResource::canCreate())->toBeFalse()
        ->and(ContractTemplateResource::canEdit($template))->toBeFalse()
        ->and(ContractTemplateResource::canDelete($template))->toBeFalse();

    $this->get(ContractTemplateResource::getUrl('index'))->assertOk();
    $this->get(ContractTemplateResource::getUrl('view', ['record' => $template->getRouteKey()]))->assertOk();
    $this->get(ContractTemplateResource::getUrl('create'))->assertForbidden();
    $this->get(ContractTemplateResource::getUrl('edit', ['record' => $template->getRouteKey()]))->assertForbidden();
})->with(['receptionist', 'accountant', 'supervisor']);

it('hides the write actions from a reader', function () {
    Auth::login($this->receptionist);

    $template = contractTemplateForTest();

    Livewire::test(ListContractTemplates::class)
        ->assertTableActionVisible('view', record: $template)
        ->assertTableActionHidden('set_default', record: $template)
        ->assertTableActionHidden('edit', record: $template)
        ->assertTableActionHidden('delete', record: $template);
});

/**
 * Hiding a button is not the guarantee — a table action runs in place over Livewire
 * without ever visiting the page whose canAccess()/canEdit() would have stopped it.
 * Filament's own EditAction and DeleteAction carry no authorization of their own, so
 * this asserts the resource supplies it.
 */
it('refuses to run a write action for a reader', function () {
    Auth::login($this->receptionist);

    $template = contractTemplateForTest();

    try {
        Livewire::test(ListContractTemplates::class)->callTableAction('delete', $template);
    } catch (Throwable) {
        // Filament may refuse by throwing rather than by no-op; either is a refusal.
    }

    expect(ContractTemplate::find($template->id))->not->toBeNull();
});

it('denies the maintenance officer entirely', function () {
    Auth::login($this->maintenance);

    expect(ContractTemplateResource::canAccess())->toBeFalse();

    $this->get(ContractTemplateResource::getUrl('index'))->assertForbidden();
});

// -----------------------------------------------------------------------
// Delete — refused where contracts were rendered from the template
// -----------------------------------------------------------------------

it('allows deleting a template no contract was ever rendered from', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();

    expect(ContractTemplateResource::canDelete($template))->toBeTrue();

    Livewire::test(ListContractTemplates::class)
        ->assertTableActionVisible('delete', record: $template);
});

it('refuses to delete a template contracts were rendered from', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();
    contractRenderedFromTemplate($template);

    expect(ContractTemplateResource::canDelete($template))->toBeFalse();

    Livewire::test(ListContractTemplates::class)
        ->assertTableActionHidden('delete', record: $template);
});

it('has no bulk delete at all', function () {
    Auth::login($this->manager);

    $page = Livewire::test(ListContractTemplates::class);
    $bulkActions = $page->instance()->getTable()->getBulkActions();

    expect($bulkActions)->toBe([]);
});

// -----------------------------------------------------------------------
// Edit — terms_version bumps on body change, locale freezes once used
// -----------------------------------------------------------------------

it('bumps terms_version when the body changes', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();

    Livewire::test(EditContractTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body' => 'New terms entirely.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->terms_version)->toBe('1.1');
});

it('keeps terms_version when the body is untouched', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();

    Livewire::test(EditContractTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['name' => 'Renamed only'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->terms_version)->toBe('1.0');
});

it('makes terms_version read-only — it is auto-managed', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();

    Livewire::test(EditContractTemplate::class, ['record' => $template->getRouteKey()])
        ->assertFormFieldDisabled('terms_version');
});

it('freezes the locale once contracts were rendered from the template', function () {
    Auth::login($this->manager);

    $used = contractTemplateForTest();
    contractRenderedFromTemplate($used);

    $unused = contractTemplateForTest(['name' => 'Unused template', 'locale' => 'en']);

    Livewire::test(EditContractTemplate::class, ['record' => $used->getRouteKey()])
        ->assertFormFieldDisabled('locale');

    Livewire::test(EditContractTemplate::class, ['record' => $unused->getRouteKey()])
        ->assertFormFieldEnabled('locale');
});

/**
 * A disabled field is a UI property; the guarantee that matters is that a submitted
 * locale is ignored server-side. Changing it would silently redirect which template
 * future contracts pick up.
 */
it('ignores a submitted locale on a frozen template', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();
    contractRenderedFromTemplate($template);

    Livewire::test(EditContractTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['locale' => 'ar'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->locale)->toBe(Locale::French);
});

/** `terms_version` is provenance — it must come from the bump, never from the form. */
it('ignores a submitted terms_version', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest();

    Livewire::test(EditContractTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['name' => 'Renamed', 'terms_version' => '99.9'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->terms_version)->toBe('1.0');
});

// -----------------------------------------------------------------------
// Create — starts at version 1.0
// -----------------------------------------------------------------------

it('starts a new template at terms version 1.0', function () {
    Auth::login($this->manager);

    Livewire::test(CreateContractTemplate::class)
        ->fillForm([
            'name' => 'Arabic rental contract',
            'locale' => 'ar',
            'body' => 'عقد كراء',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ContractTemplate::where('name', 'Arabic rental contract')->firstOrFail()->terms_version)
        ->toBe('1.0');
});

// -----------------------------------------------------------------------
// set_default — clears the previous default for the same locale
// -----------------------------------------------------------------------

it('clears the previous default for the same locale', function () {
    Auth::login($this->manager);

    $first = contractTemplateForTest(['name' => 'First', 'is_default' => true]);
    $second = contractTemplateForTest(['name' => 'Second']);

    Livewire::test(ListContractTemplates::class)
        ->callTableAction('set_default', $second)
        ->assertHasNoTableActionErrors();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('keeps defaults of other locales alone', function () {
    Auth::login($this->manager);

    $frDefault = contractTemplateForTest(['name' => 'French default', 'is_default' => true]);
    $en = contractTemplateForTest(['name' => 'English', 'locale' => 'en']);

    Livewire::test(ListContractTemplates::class)
        ->callTableAction('set_default', $en)
        ->assertHasNoTableActionErrors();

    expect($frDefault->fresh()->is_default)->toBeTrue()
        ->and($en->fresh()->is_default)->toBeTrue();
});

/**
 * A template carries a `branch_id`, so exclusivity is per branch and locale. One
 * branch promoting its own template must not strip another branch's default and
 * leave it renting cars on whatever `resolveTemplate()` happens to pick.
 */
it('keeps another branch default alone', function () {
    Auth::login($this->manager);

    $other = Branch::factory()->create(['code' => 'ORAN']);

    $otherDefault = contractTemplateForTest([
        'name' => 'Oran French', 'branch_id' => $other->id, 'is_default' => true,
    ]);
    $mine = contractTemplateForTest(['name' => 'Main French']);

    Livewire::test(ListContractTemplates::class)
        ->callTableAction('set_default', $mine)
        ->assertHasNoTableActionErrors();

    expect($otherDefault->fresh()->is_default)->toBeTrue()
        ->and($mine->fresh()->is_default)->toBeTrue();
});

/**
 * Regression: the demotion used to key on the *stored* locale, so moving a template
 * to another locale while claiming the default demoted the locale it was leaving and
 * left the one it joined with two defaults — making template selection arbitrary.
 */
it('demotes the default of the locale being moved into, not the one left behind', function () {
    Auth::login($this->manager);

    $enDefault = contractTemplateForTest([
        'name' => 'English default', 'locale' => 'en', 'is_default' => true,
    ]);
    $fr = contractTemplateForTest(['name' => 'French one']);

    Livewire::test(EditContractTemplate::class, ['record' => $fr->getRouteKey()])
        ->fillForm(['locale' => 'en', 'is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fr->fresh()->locale)->toBe(Locale::English)
        ->and($fr->fresh()->is_default)->toBeTrue()
        ->and($enDefault->fresh()->is_default)->toBeFalse()
        ->and(ContractTemplate::where('locale', 'en')->where('is_default', true)->count())->toBe(1);
});

it('demotes the previous default when a new template is created as default', function () {
    Auth::login($this->manager);

    $existing = contractTemplateForTest(['name' => 'Old default', 'is_default' => true]);

    Livewire::test(CreateContractTemplate::class)
        ->fillForm([
            'name' => 'New default',
            'locale' => 'fr',
            'body' => 'Nouvelles conditions.',
            'is_default' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($existing->fresh()->is_default)->toBeFalse()
        ->and(ContractTemplate::where('locale', 'fr')->where('is_default', true)->count())->toBe(1);
});

// -----------------------------------------------------------------------
// View — the preview renders with sample data and RTL for Arabic
// -----------------------------------------------------------------------

it('renders the preview with sample data substituted', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest([
        'body' => "{{customer_name}} — {{car_description}}\nTotal: {{total_amount}} DZD",
    ]);

    $this->get(ContractTemplateResource::getUrl('view', ['record' => $template->getRouteKey()]))
        ->assertOk()
        ->assertSee('Ahmed Benali')
        ->assertSee('Renault Clio 4 — 2022')
        ->assertSee('58,500 DZD')
        // The locale is a cast enum now, so it must show its label, not `fr`.
        ->assertSee(Locale::French->getLabel());
});

it('shows the locale label rather than its code in the list', function () {
    Auth::login($this->manager);

    contractTemplateForTest(['locale' => 'ar']);

    Livewire::test(ListContractTemplates::class)
        ->assertSee(Locale::Arabic->getLabel());
});

it('renders an Arabic template right-to-left', function () {
    Auth::login($this->manager);

    $template = contractTemplateForTest([
        'name' => 'Arabic rental contract',
        'locale' => 'ar',
        'body' => 'عقد كراء',
    ]);

    $this->get(ContractTemplateResource::getUrl('view', ['record' => $template->getRouteKey()]))
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

// -----------------------------------------------------------------------
// Index — locale filter
// -----------------------------------------------------------------------

it('filters the list by locale', function () {
    Auth::login($this->manager);

    $fr = contractTemplateForTest(['name' => 'French']);
    $ar = contractTemplateForTest(['name' => 'Arabic', 'locale' => 'ar']);

    Livewire::test(ListContractTemplates::class)
        ->filterTable('locale', 'fr')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$fr]);
});

// -----------------------------------------------------------------------
// Selection — which template a contract actually renders from
// -----------------------------------------------------------------------

it('prefers the default template of the locale', function () {
    $other = contractTemplateForTest(['name' => 'Not default']);
    $default = contractTemplateForTest(['name' => 'The default', 'is_default' => true]);

    $resolved = app(ContractService::class)->resolveTemplate('fr', $this->branch->id);

    expect($resolved?->id)->toBe($default->id);
});

it('prefers a branch template over a global one', function () {
    globalContractTemplateForTest(['name' => 'Global', 'is_default' => true]);
    $mine = contractTemplateForTest(['name' => 'Branch', 'is_default' => true]);

    expect(app(ContractService::class)->resolveTemplate('fr', $this->branch->id)?->id)->toBe($mine->id);
});

it('falls back to a global template when the branch has none', function () {
    $global = globalContractTemplateForTest(['name' => 'Global']);

    expect(app(ContractService::class)->resolveTemplate('fr', $this->branch->id)?->id)->toBe($global->id);
});

it('never picks another branch template', function () {
    $other = Branch::factory()->create(['code' => 'ORAN']);
    contractTemplateForTest(['name' => 'Oran only', 'branch_id' => $other->id, 'is_default' => true]);

    expect(app(ContractService::class)->resolveTemplate('fr', $this->branch->id))->toBeNull();
});

it('ignores inactive templates', function () {
    contractTemplateForTest(['name' => 'Retired', 'is_active' => false, 'is_default' => true]);

    expect(app(ContractService::class)->resolveTemplate('fr', $this->branch->id))->toBeNull();
});

// -----------------------------------------------------------------------
// Schema — the locale vocabulary is enforced by the database, not just the form
// -----------------------------------------------------------------------

it('rejects a locale outside the enum at the database level', function () {
    expect(fn () => DB::table('contract_templates')->insert([
        'branch_id' => $this->branch->id,
        'name' => 'Klingon terms',
        'locale' => 'tlh',
        'body' => 'x',
        'terms_version' => '1.0',
        'is_active' => true,
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
