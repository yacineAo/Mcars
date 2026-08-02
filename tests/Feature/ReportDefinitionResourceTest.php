<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\ExportFormat;
use App\Enums\Locale;
use App\Enums\NotificationStatus;
use App\Enums\ReportType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ReportDefinitionResource;
use App\Filament\Admin\Resources\ReportDefinitionResource\Pages\CreateReportDefinition;
use App\Filament\Admin\Resources\ReportDefinitionResource\Pages\EditReportDefinition;
use App\Filament\Admin\Resources\ReportDefinitionResource\Pages\ListReportDefinitions;
use App\Filament\Admin\Resources\ReportDefinitionResource\Pages\ViewReportDefinition;
use App\Jobs\ExportJob;
use App\Mail\ScheduledReportMail;
use App\Models\AlertRule;
use App\Models\Branch;
use App\Models\NotificationLog;
use App\Models\PendingExport;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Reporting\ReportDataResolver;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(ChartOfAccountSeeder::class);
    $this->seed(FinancialAccountSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);
});

function makeDefinition(array $overrides = []): ReportDefinition
{
    $branch = Branch::query()->where('code', 'MAIN')->firstOrFail();

    return ReportDefinition::create(array_merge([
        'branch_id' => $branch->id,
        'user_id' => User::query()->firstOrFail()->id,
        'name' => 'Monthly P&L',
        'report_type' => ReportType::ProfitAndLoss->value,
        'format' => ExportFormat::Pdf->value,
        'parameters' => [],
        'schedule_cron' => '0 8 * * 1',
        'schedule_email' => 'office@mcars.dz',
        'schedule_enabled' => true,
        'last_sent_at' => null,
    ], $overrides));
}

function makeRun(ReportDefinition $definition, array $overrides = []): PendingExport
{
    return PendingExport::factory()->create(array_merge([
        'branch_id' => $definition->branch_id,
        'user_id' => $definition->user_id,
        'report_definition_id' => $definition->id,
        'report_type' => $definition->report_type->value,
        'format' => $definition->format->value,
        'parameters' => $definition->parameters,
        'status' => 'completed',
        'file_path' => 'exports/test.pdf',
        'file_name' => 'test.pdf',
        'file_size' => 2048,
    ], $overrides));
}

// ------------------------------------------------------------------- access

it('keeps saved reports behind the financials permission', function () {
    expect(ReportDefinitionResource::canAccess())->toBeFalse();

    $this->actingAs($this->receptionist)
        ->get(ReportDefinitionResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(ReportDefinitionResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();
});

it('hides the view page from users without the financials permission', function () {
    $definition = makeDefinition();

    $this->actingAs($this->receptionist)
        ->get(ReportDefinitionResource::getUrl('view', ['record' => $definition], panel: 'admin'))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(ReportDefinitionResource::getUrl('view', ['record' => $definition], panel: 'admin'))
        ->assertSuccessful();
});

// -------------------------------------------------------------------- view

it('renders the view page with the definition, schedule and run history', function () {
    $definition = makeDefinition();
    makeRun($definition);
    makeRun($definition, [
        'status' => 'failed',
        'error_message' => 'Something went wrong',
    ]);

    $nextRun = $definition->nextRunAt();

    Livewire::actingAs($this->manager)
        ->test(ViewReportDefinition::class, ['record' => $definition->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeText($definition->name)
        ->assertSeeText($nextRun->format('d/m/Y H:i'))
        ->assertSeeText('Something went wrong');
});

it('runs a saved report now and dispatches the job', function () {
    Queue::fake();

    $definition = makeDefinition();

    Livewire::actingAs($this->manager)
        ->test(ListReportDefinitions::class)
        ->callTableAction('run_now', $definition->id)
        ->callMountedTableAction()
        ->assertHasNoErrors();

    dump('runs in db: '.PendingExport::count().' def id: '.$definition->id);
    $run = PendingExport::query()->where('report_definition_id', $definition->id)->firstOrFail();

    expect($run->status)->toBe('pending')
        ->and($run->report_type)->toBe($definition->report_type)
        ->and($run->format)->toBe($definition->format)
        // The same last-month window the cron sweep uses.
        ->and($run->parameters['from'])->toBe(CarbonImmutable::now()->subMonth()->startOfMonth()->format('Y-m-d'))
        ->and($run->parameters['to'])->toBe(CarbonImmutable::now()->subMonth()->endOfMonth()->format('Y-m-d'))
        ->and($definition->fresh()->last_sent_at)->not->toBeNull();

    Queue::assertPushed(ExportJob::class);
});

// ------------------------------------------------------------------- cron

it('rejects an invalid cron expression and shows the next-run preview for a valid one', function () {
    Livewire::actingAs($this->manager)
        ->test(CreateReportDefinition::class)
        ->fillForm([
            'name' => 'Bad cron',
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'schedule_cron' => 'not a cron',
        ])
        ->call('create')
        ->assertHasFormErrors(['schedule_cron']);

    $nextRun = ReportDefinition::runTimes('0 8 * * 1', 3)[0];

    Livewire::actingAs($this->manager)
        ->test(CreateReportDefinition::class)
        ->fillForm([
            'name' => 'Good cron',
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'schedule_cron' => '0 8 * * 1',
        ])
        ->assertSee($nextRun->format('d/m/Y H:i'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ReportDefinition::query()->where('name', 'Good cron')->exists())->toBeTrue();
});

it('accepts a blank cron expression', function () {
    Livewire::actingAs($this->manager)
        ->test(CreateReportDefinition::class)
        ->fillForm([
            'name' => 'No schedule yet',
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'schedule_cron' => '',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ReportDefinition::query()->where('name', 'No schedule yet')->exists())->toBeTrue();
});

it('validates recipient lists and accepts comma-separated addresses', function () {
    Livewire::actingAs($this->manager)
        ->test(CreateReportDefinition::class)
        ->fillForm([
            'name' => 'Bad recipients',
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'schedule_email' => 'office@mcars.dz, not-an-email',
        ])
        ->call('create')
        ->assertHasFormErrors(['schedule_email']);

    Livewire::actingAs($this->manager)
        ->test(CreateReportDefinition::class)
        ->fillForm([
            'name' => 'Two recipients',
            'report_type' => ReportType::ProfitAndLoss->value,
            'format' => ExportFormat::Pdf->value,
            'schedule_email' => 'office@mcars.dz, boss@mcars.dz',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ReportDefinition::query()->where('name', 'Two recipients')->firstOrFail()->scheduleEmailRecipients())
        ->toBe(['office@mcars.dz', 'boss@mcars.dz']);
});

// ------------------------------------------------------------------- index

it('shows the next run and flags enabled schedules that never sent', function () {
    // The page renders in the signed-in user's stored locale; the factory users
    // are French, so pin this one to English to assert the indicator text.
    $this->manager->update(['locale' => Locale::English->value]);

    makeDefinition(); // enabled, cron set, never sent
    makeDefinition([
        'name' => 'Old and sent',
        'schedule_enabled' => true,
        'last_sent_at' => CarbonImmutable::now()->subDays(30),
    ]);
    makeDefinition([
        'name' => 'Disabled',
        'schedule_enabled' => false,
    ]);

    $nextRun = ReportDefinition::query()->where('name', 'Monthly P&L')->firstOrFail()->nextRunAt();

    app()->setLocale('en');

    Livewire::actingAs($this->manager)
        ->test(ListReportDefinitions::class)
        ->assertSuccessful()
        ->assertSeeText('Never sent')
        ->assertSeeText($nextRun->format('d/m/Y H:i'));

    Livewire::actingAs($this->manager)
        ->test(ListReportDefinitions::class)
        ->filterTable('schedule_state', 'enabled_never_sent')
        ->assertCountTableRecords(1);
});

// ------------------------------------------------------------------- edit

it('freezes the report type once the definition has runs', function () {
    $definition = makeDefinition();
    makeRun($definition);

    Livewire::actingAs($this->manager)
        ->test(EditReportDefinition::class, ['record' => $definition->getRouteKey()])
        ->assertFormFieldIsDisabled('report_type');

    // Scope, schedule and format stay editable.
    $definitionWithoutRuns = makeDefinition(['name' => 'Never ran']);

    Livewire::actingAs($this->manager)
        ->test(EditReportDefinition::class, ['record' => $definitionWithoutRuns->getRouteKey()])
        ->assertFormFieldIsEnabled('report_type');
});

// ------------------------------------------------------------------ delete

it('keeps run history when a definition is deleted', function () {
    $definition = makeDefinition();
    $run = makeRun($definition);

    // The resource's delete is a soft delete: the schedule stops but the rows
    // stay linked, so the history keeps rendering.
    $definition->delete();

    expect($run->fresh()->report_definition_id)->toBe($definition->id);

    // A hard delete (only ever reached through forceDelete) must not discard the
    // audit of what was emailed either — the foreign key nulls instead of cascading.
    $definition->forceDelete();

    $kept = $run->fresh();

    expect($kept)->not->toBeNull()
        ->and($kept->report_definition_id)->toBeNull();
});

// ------------------------------------------------------------------- email

it('emails every recipient of a completed scheduled run', function () {
    Storage::fake('private');
    Mail::fake();

    $definition = makeDefinition([
        'schedule_email' => 'office@mcars.dz, boss@mcars.dz',
        'format' => ExportFormat::Csv->value,
    ]);

    $run = PendingExport::factory()->create([
        'branch_id' => $definition->branch_id,
        'user_id' => $definition->user_id,
        'report_definition_id' => $definition->id,
        'report_type' => $definition->report_type->value,
        'format' => ExportFormat::Csv->value,
        'parameters' => $definition->parameters,
        'status' => 'pending',
    ]);

    (new ExportJob($run, $this->manager->id))->handle(
        app(ReportService::class),
        app(ReportDataResolver::class),
    );

    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail): bool {
        return $mail->hasTo('office@mcars.dz') && $mail->hasTo('boss@mcars.dz');
    });
});

// ------------------------------------------------------------- cron sweep

it('fires the scheduled sweep at the due minute and not before', function () {
    Queue::fake();

    $due = makeDefinition([
        'name' => 'Due now',
        'schedule_cron' => '0 8 * * 1', // every Monday 08:00
        'last_sent_at' => null,
    ]);

    $notDue = makeDefinition([
        'name' => 'Not due',
        'schedule_cron' => '0 9 * * 1', // 09:00 — different minute
        'last_sent_at' => null,
    ]);

    $mondayAtEight = CarbonImmutable::parse('2026-09-07 08:00:00');

    // 07:59 on the same Monday: nothing is due yet.
    $this->artisan('reports:run-scheduled', ['--now' => '2026-09-07 07:59:00'])->assertSuccessful();
    expect($due->fresh()->last_sent_at)->toBeNull();

    // 08:00: exactly the due minute fires, and only it.
    $this->artisan('reports:run-scheduled', ['--now' => $mondayAtEight->toDateTimeString()])->assertSuccessful();

    expect($due->fresh()->last_sent_at)->not->toBeNull()
        ->and($notDue->fresh()->last_sent_at)->toBeNull();

    $run = $due->fresh()->pendingExports()->firstOrFail();

    // The runner applies the last completed month as the report window.
    expect($run->parameters['from'])->toBe('2026-08-01')
        ->and($run->parameters['to'])->toBe('2026-08-31');

    Queue::assertPushed(ExportJob::class, 1);
});

// ------------------------------------------------------- alert on failure

it('raises an alert for a saved report with a failed run', function () {
    $definition = makeDefinition();
    makeRun($definition, ['status' => 'failed', 'failed_at' => CarbonImmutable::now()->subHour()]);

    $rule = AlertRule::factory()
        ->ofType(AlertType::ScheduledReportFailed)
        ->forRoles([UserRole::Manager])
        ->create();

    Queue::fake();

    expect(app(NotificationService::class)->evaluate($rule, CarbonImmutable::now()))->toBe(1);

    $log = NotificationLog::query()->firstOrFail();

    expect($log->status)->toBe(NotificationStatus::Queued)
        ->and($log->payload['name'])->toBe($definition->name)
        ->and($log->payload['failed_at'])->not->toBeNull()
        ->and($log->user_id)->toBe($this->manager->id);
});

it('does not alert for a saved report whose runs all succeeded', function () {
    $definition = makeDefinition();
    makeRun($definition);

    $rule = AlertRule::factory()
        ->ofType(AlertType::ScheduledReportFailed)
        ->forRoles([UserRole::Manager])
        ->create();

    expect(app(NotificationService::class)->evaluate($rule, CarbonImmutable::now()))->toBe(0);
    expect(NotificationLog::query()->count())->toBe(0);
});

it('scopes the scheduled-report-failed alert to the rule branch', function () {
    $definition = makeDefinition();
    makeRun($definition, ['status' => 'failed']);

    $otherBranch = Branch::factory()->create(['code' => 'ORAN']);

    $rule = AlertRule::factory()
        ->ofType(AlertType::ScheduledReportFailed)
        ->forRoles([UserRole::Manager])
        ->create(['branch_id' => $otherBranch->id]);

    expect(app(NotificationService::class)->evaluate($rule, CarbonImmutable::now()))->toBe(0);
    expect(NotificationLog::query()->count())->toBe(0);
});
