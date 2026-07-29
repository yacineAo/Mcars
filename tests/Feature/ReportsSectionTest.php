<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Enums\UserRole;
use App\Filament\Admin\Panels\AdminPanelProvider;
use App\Filament\Admin\Resources\ReportDefinitionResource;
use App\Filament\Admin\Resources\ReportResource;
use App\Filament\Admin\Resources\ReportResource\Pages\CreateReport;
use App\Jobs\ExportJob;
use App\Livewire\BranchSwitcher;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;
use App\Models\PendingExport;
use App\Models\User;
use App\Services\Reporting\ReportDataResolver;
use App\Services\Reporting\ReportRequest;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionProperty;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(FinancialAccountSeeder::class);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);
});

/**
 * Build a report run of the given type, filling in whatever entity that type needs.
 */
function makeReport(ReportType $type, User $user, Branch $branch, array $overrides = []): PendingExport
{
    $parameters = [
        'branch_id' => $branch->id,
        'from' => CarbonImmutable::today()->startOfMonth()->toDateString(),
        'to' => CarbonImmutable::today()->endOfMonth()->toDateString(),
    ];

    $parameters += match ($type) {
        ReportType::OwnerStatement => ['car_owner_id' => CarOwner::factory()->create(['branch_id' => $branch->id])->id],
        default => [],
    };

    return PendingExport::create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'report_type' => $type->value,
        'format' => ExportFormat::Pdf->value,
        'parameters' => $parameters,
        'status' => 'pending',
        ...$overrides,
    ]);
}

it('exposes exactly one reports entry point', function () {
    $panel = Filament::getPanel('admin');

    // The Reports Hub page and PendingExportResource listed the same rows with the
    // same actions. Whichever one comes back, this fails.
    $surfaces = collect($panel->getResources())
        ->filter(fn (string $resource): bool => $resource::getModel() === PendingExport::class);

    expect($surfaces)->toHaveCount(1)
        ->and($surfaces->first())->toBe(ReportResource::class);

    $pages = collect($panel->getPages())->map(fn (string $page): string => class_basename($page));
    expect($pages)->not->toContain('ReportsHubPage');

    // /admin/reports must be the resource, not a second page fighting it for the slug.
    expect(ReportResource::getUrl('index', panel: 'admin'))->toEndWith('/admin/reports');
});

it('nests saved schedules under the reports item in every locale', function () {
    // Filament matches the parent by rendered label, and the app runs in French.
    // A hardcoded 'Reports' would silently orphan the item.
    foreach (['en', 'fr', 'ar'] as $locale) {
        app()->setLocale($locale);

        expect(ReportDefinitionResource::getNavigationParentItem())
            ->toBe(ReportResource::getNavigationLabel())
            ->and(ReportDefinitionResource::getNavigationGroup())
            ->toBe(ReportResource::getNavigationGroup());
    }
});

it('renders the report view page for every report type', function () {
    $this->actingAs($this->manager);

    Customer::factory()->create(['branch_id' => $this->branch->id]);
    Car::factory()->create(['branch_id' => $this->branch->id]);

    $failures = [];

    foreach (ReportType::cases() as $type) {
        $report = makeReport($type, $this->manager, $this->branch);

        try {
            $status = $this->get(ReportResource::getUrl('view', ['record' => $report], panel: 'admin'))
                ->getStatusCode();

            if ($status >= 400) {
                $failures[] = $type->value.' → HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $failures[] = $type->value.' → '.mb_substr($e->getMessage(), 0, 160);
        }
    }

    expect($failures)->toBe([]);
});

it('gates the reports section on reports.view_financials', function () {
    expect($this->manager->can('reports.view_financials'))->toBeTrue()
        ->and($this->receptionist->can('reports.view_financials'))->toBeFalse();

    $this->actingAs($this->receptionist);
    expect(ReportResource::canAccess())->toBeFalse();

    // Not just the nav item — the pages themselves must refuse.
    $this->get(ReportResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(ReportResource::getUrl('create', panel: 'admin'))->assertForbidden();

    $this->actingAs($this->manager);
    expect(ReportResource::canAccess())->toBeTrue();
    $this->get(ReportResource::getUrl('index', panel: 'admin'))->assertSuccessful();
});

it('shows a user only their own runs unless they can view all branches', function () {
    $other = User::factory()->create(['branch_id' => $this->branch->id]);
    $other->assignRole(UserRole::Accountant->value);

    $mine = makeReport(ReportType::ProfitAndLoss, $other, $this->branch);
    $theirs = makeReport(ReportType::CashFlow, $this->manager, $this->branch);

    // An accountant has reports.view_financials but not branches.view_all.
    $this->actingAs($other);
    expect($other->can('branches.view_all'))->toBeFalse();

    $visible = ReportResource::getEloquentQuery()->pluck('id');
    expect($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($theirs->id);

    // A manager holds branches.view_all and sees the whole archive — matching the
    // rule ExportController applies to the file download itself.
    $this->actingAs($this->manager);
    $visible = ReportResource::getEloquentQuery()->pluck('id');
    expect($visible)->toContain($mine->id, $theirs->id);
});

it('resolves the same figures for the screen and the export', function () {
    $from = CarbonImmutable::today()->startOfMonth();
    $to = CarbonImmutable::today()->endOfMonth();

    $report = makeReport(ReportType::ProfitAndLoss, $this->manager, $this->branch);

    $resolved = app(ReportDataResolver::class)->resolve(ReportRequest::fromPendingExport($report));
    $direct = app(ReportService::class)->profitAndLoss($from, $to, $this->branch->id);

    expect($resolved)->toBe($direct);
});

it('does not claim a period for receivables ageing', function () {
    expect(ReportType::ReceivablesAgeing->isPeriodic())->toBeFalse();

    foreach (ReportType::cases() as $type) {
        if ($type !== ReportType::ReceivablesAgeing) {
            expect($type->isPeriodic())->toBeTrue();
        }
    }

    $report = makeReport(ReportType::ReceivablesAgeing, $this->manager, $this->branch);

    expect(ReportResource::describeScope($report))->not->toContain('→');
});

it('knows which entity each report type can be narrowed to', function () {
    expect(ReportType::CustomerReport->scopeField())->toBe('customer_id')
        ->and(ReportType::FleetProfitability->scopeField())->toBe('car_id')
        ->and(ReportType::OwnerStatement->scopeField())->toBe('car_owner_id')
        ->and(ReportType::ProfitAndLoss->scopeField())->toBeNull();

    // Only the owner statement is meaningless without its entity.
    expect(ReportType::OwnerStatement->requiresScope())->toBeTrue()
        ->and(ReportType::CustomerReport->requiresScope())->toBeFalse();
});

it('queues a report from the create form and lands on its figures', function () {
    Queue::fake();

    $this->actingAs($this->manager);

    $owner = CarOwner::factory()->create(['branch_id' => $this->branch->id]);

    Livewire::test(CreateReport::class)
        ->fillForm([
            'report_type' => ReportType::OwnerStatement->value,
            'car_owner_id' => $owner->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'format' => ExportFormat::Xlsx->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $report = PendingExport::query()->latest('id')->firstOrFail();

    expect($report->report_type)->toBe(ReportType::OwnerStatement)
        ->and($report->format)->toBe(ExportFormat::Xlsx)
        ->and($report->user_id)->toBe($this->manager->id)
        ->and($report->parameters['car_owner_id'])->toBe($owner->id)
        ->and($report->parameters['from'])->toBe('2026-07-01');

    Queue::assertPushed(ExportJob::class);

    // The figures must be readable immediately, without waiting on the queue.
    $this->get(ReportResource::getUrl('view', ['record' => $report], panel: 'admin'))
        ->assertSuccessful();
});

it('omits the period for a report that has none', function () {
    Queue::fake();

    $this->actingAs($this->manager);

    Livewire::test(CreateReport::class)
        ->fillForm([
            'report_type' => ReportType::ReceivablesAgeing->value,
            'format' => ExportFormat::Csv->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $report = PendingExport::query()->latest('id')->firstOrFail();

    expect($report->parameters)->not->toHaveKey('from')
        ->and($report->parameters)->not->toHaveKey('to');
});

it('refuses an owner statement with no owner', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateReport::class)
        ->fillForm([
            'report_type' => ReportType::OwnerStatement->value,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'format' => ExportFormat::Pdf->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['car_owner_id']);
});

it('keeps the branch chosen in the form over the row default', function () {
    // "All branches" is a deliberate null, not a missing value — the queue has no
    // session to fall back on, so the payload must win.
    $report = PendingExport::create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->manager->id,
        'report_type' => ReportType::CashFlow->value,
        'format' => ExportFormat::Csv->value,
        'parameters' => ['branch_id' => null, 'from' => '2026-01-01', 'to' => '2026-01-31'],
        'status' => 'pending',
    ]);

    expect(ReportRequest::fromPendingExport($report)->branchId)->toBeNull();
});

it('renders the branch switcher only when multi-branch is on', function () {
    // config/branches.php documents the switcher as disabled with the flag off —
    // otherwise it offers a choice BranchScope and BranchContext would then ignore.
    //
    // Asserting on the *rendered* hook, not merely that the hook is registered: the
    // locale switcher shares that hook and registers unconditionally, so a
    // registration-only assertion passes whatever the branch flag does.
    $renderWhen = function (bool $enabled): string {
        config()->set('branches.enabled', $enabled);

        $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

        $hooks = (new ReflectionProperty($panel, 'renderHooks'))->getValue($panel);

        return (string) collect($hooks[PanelsRenderHook::GLOBAL_SEARCH_AFTER][''] ?? [])
            ->map(fn (Closure $hook): string => (string) $hook())
            ->implode('');
    };

    $this->actingAs($this->manager);

    $off = $renderWhen(false);
    $on = $renderWhen(true);

    // Livewire renders each component's class as a snapshot attribute, so the presence
    // of the component is checkable without depending on its markup.
    expect($off)->not->toContain('branch-switcher')
        ->and($on)->toContain('branch-switcher')
        // The locale switcher is present either way.
        ->and($off)->toContain('locale-switcher')
        ->and($on)->toContain('locale-switcher');
});

it('renders the branch switcher from filament components', function () {
    config()->set('branches.enabled', true);

    $this->actingAs($this->manager);

    // The old markup was hand-written Alpine plus Tailwind utilities that the panel's
    // compiled stylesheet does not contain, so it rendered unstyled.
    Livewire::test(BranchSwitcher::class)
        ->assertOk()
        ->assertSeeHtml('fi-dropdown')
        ->assertDontSeeHtml('x-data="{ open: false }"');
});

it('refuses to switch to a branch the user cannot reach', function () {
    config()->set('branches.enabled', true);

    $other = Branch::factory()->create(['code' => 'OTHER', 'is_default' => false]);

    // A receptionist has no branches.view_all, so accessibleBranchIds() is just their
    // own branch — the switcher must not take an id they submitted anyway.
    $this->actingAs($this->receptionist);

    Livewire::test(BranchSwitcher::class)
        ->call('switch', $other->id)
        ->assertSet('activeBranchId', null);

    expect(session('branch_context_active_id'))->toBeNull();

    // Their own branch is accepted.
    Livewire::test(BranchSwitcher::class)
        ->call('switch', $this->branch->id)
        ->assertSet('activeBranchId', $this->branch->id);
});

it('produces a real file for every report type in every format', function () {
    Storage::fake('private');

    Car::factory()->count(2)->create(['branch_id' => $this->branch->id]);
    Customer::factory()->create(['branch_id' => $this->branch->id]);

    $failures = [];

    foreach (ReportType::cases() as $type) {
        foreach (ExportFormat::cases() as $format) {
            $report = makeReport($type, $this->manager, $this->branch, ['format' => $format->value]);

            (new ExportJob($report, $this->manager->id))->handle(
                app(ReportService::class),
                app(ReportDataResolver::class),
            );

            $report->refresh();

            if (! $report->isCompleted()) {
                $failures[] = "{$type->value}/{$format->value}: ".mb_substr((string) $report->error_message, 0, 90);

                continue;
            }

            // A row marked complete with an empty file is the failure mode that
            // hid the Excel::store() bug: the job "succeeded" and wrote nothing.
            if (($report->file_size ?? 0) <= 0) {
                $failures[] = "{$type->value}/{$format->value}: empty file";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('keeps the per-car rows in a fleet CSV', function () {
    Storage::fake('private');

    Car::factory()->create(['branch_id' => $this->branch->id, 'registration_number' => '12345-678-16']);

    $report = makeReport(ReportType::FleetProfitability, $this->manager, $this->branch, [
        'format' => ExportFormat::Csv->value,
    ]);

    (new ExportJob($report, $this->manager->id))->handle(
        app(ReportService::class),
        app(ReportDataResolver::class),
    );

    $csv = Storage::disk('private')->get($report->refresh()->file_path);

    // CSV holds one sheet. The fleet export must flatten to the detail sheet, not
    // the four-line summary — the cars are the report.
    expect($csv)->toContain('12345-678-16')
        ->and($csv)->toContain('Reg No');
});

it('renders floats without binary noise', function () {
    // docker/php/php.ini had precision = 17, which put 36.799999999999997 into
    // exported spreadsheets in place of 36.8.
    expect((string) 36.8)->toBe('36.8')
        ->and((string) 17.3)->toBe('17.3');
});
