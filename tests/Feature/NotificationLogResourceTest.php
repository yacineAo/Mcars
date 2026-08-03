<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AlertRuleResource;
use App\Filament\Admin\Resources\NotificationLogResource;
use App\Filament\Admin\Resources\NotificationLogResource\Pages\ListNotificationLogs;
use App\Models\Activity;
use App\Models\AlertRule;
use App\Models\Branch;
use App\Models\CarDocument;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->otherBranch = Branch::factory()->create(['code' => 'ORAN']);

    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->rule = AlertRule::factory()->ofType(AlertType::BookingReturnDue)->create();
});

it('exposes no create, edit, delete, bulk or resend action', function () {
    $log = NotificationLog::factory()->create(['alert_rule_id' => $this->rule->id]);

    expect(NotificationLogResource::canCreate())->toBeFalse()
        ->and(NotificationLogResource::canEdit($log))->toBeFalse()
        ->and(NotificationLogResource::canDelete($log))->toBeFalse();

    $this->actingAs($this->manager);

    Livewire::test(ListNotificationLogs::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('resend')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('refuses the whole resource without alerts.view_logs', function () {
    $log = NotificationLog::factory()->create(['alert_rule_id' => $this->rule->id]);

    $this->actingAs($this->receptionist);

    $this->get(NotificationLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(NotificationLogResource::getUrl('view', ['record' => $log], panel: 'admin'))->assertForbidden();
});

it('pins the index to the pivot branches, not just the home branch', function () {
    $pinned = User::factory()->create(['branch_id' => $this->branch->id]);
    $pinned->givePermissionTo('alerts.view_logs');
    $pinned->branchUsers()->attach($this->otherBranch, ['is_primary' => false]);

    NotificationLog::factory()->create(['branch_id' => $this->branch->id]);
    NotificationLog::factory()->create(['branch_id' => $this->otherBranch->id]);
    NotificationLog::factory()->create(['branch_id' => null]);

    $this->actingAs($pinned);

    $rows = NotificationLogResource::getEloquentQuery()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->branch_id)->toBe($this->otherBranch->id);
});

it('denies a branch-less user without branches.view_all entirely', function () {
    // BelongsToBranch auto-fills branch_id on create, so a true "no branch"
    // account only exists after the fact — that is exactly the shape this
    // resource must refuse, mirroring ActivityLogResource's whereRaw('1 = 0').
    $orphan = User::factory()->create();
    $orphan->forceFill(['branch_id' => null])->save();
    $orphan->givePermissionTo('alerts.view_logs');

    NotificationLog::factory()->create(['branch_id' => $this->branch->id]);
    NotificationLog::factory()->create();

    $this->actingAs($orphan);

    expect(NotificationLogResource::getEloquentQuery()->get())->toBeEmpty();
});

it('shows user.name for in-app deliveries and the address otherwise', function () {
    $this->actingAs($this->manager);

    $internal = NotificationLog::factory()->create([
        'channel' => NotificationChannel::Database,
        'user_id' => $this->manager->id,
        'recipient' => (string) $this->manager->id,
    ]);

    $external = NotificationLog::factory()->create([
        'channel' => NotificationChannel::Mail,
        'recipient' => 'driver@example.dz',
    ]);

    Livewire::test(ListNotificationLogs::class)
        ->assertCanSeeTableRecords([$internal, $external])
        ->assertSee($this->manager->name)
        ->assertSee('driver@example.dz');
});

it('links the subject to its view page on the index and the view page', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

    $log = NotificationLog::factory()->about($customer)->create();

    expect(NotificationLogResource::subjectUrl($log))
        ->toContain('customers/'.$customer->getKey());

    $this->actingAs($this->manager);
    $this->get(NotificationLogResource::getUrl('view', ['record' => $log], panel: 'admin'))
        ->assertSuccessful();
});

it('leaves subjects without a view page unlinked', function () {
    $document = CarDocument::factory()->create();

    $log = NotificationLog::factory()->about($document)->create();

    expect(NotificationLogResource::subjectUrl($log))->toBeNull();
});

it('links the alert rule back to its edit page', function () {
    $log = NotificationLog::factory()->create(['alert_rule_id' => $this->rule->id]);

    $this->actingAs($this->manager);

    $url = AlertRuleResource::getUrl('edit', ['record' => $this->rule->id], panel: 'admin');

    $this->get(NotificationLogResource::getUrl('view', ['record' => $log], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee($url);
});

it('filters by alert rule type and by created_at range', function () {
    $other = AlertRule::factory()->ofType(AlertType::CarDocumentExpiring)->create();

    $old = NotificationLog::factory()->create([
        'alert_rule_id' => $this->rule->id,
        'created_at' => now()->subDays(10),
    ]);
    $fresh = NotificationLog::factory()->create([
        'alert_rule_id' => $other->id,
        'created_at' => now()->subDays(1),
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ListNotificationLogs::class)
        ->filterTable('alert_rule_type', $this->rule->type->value)
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$fresh])
        ->resetTableFilters()
        ->filterTable('created_at', ['from' => now()->subDays(3)->toDateString()])
        ->assertCanSeeTableRecords([$fresh])
        ->assertCanNotSeeTableRecords([$old]);
});

it('shows the branch filter only with branches.view_all', function () {
    $logsOnly = User::factory()->create(['branch_id' => $this->branch->id]);
    $logsOnly->givePermissionTo('alerts.view_logs');

    $this->actingAs($this->manager);

    Livewire::test(ListNotificationLogs::class)
        ->assertTableFilterVisible('branch_id');

    $this->actingAs($logsOnly);

    Livewire::test(ListNotificationLogs::class)
        ->assertTableFilterHidden('branch_id');
});

it('prunes only terminal rows past the horizon, never in-flight ones', function () {
    $oldSent = NotificationLog::factory()
        ->status(NotificationStatus::Sent)
        ->create(['created_at' => now()->subYears(2)]);
    $oldQueued = NotificationLog::factory()
        ->create(['created_at' => now()->subYears(2)]);
    $oldSending = NotificationLog::factory()
        ->status(NotificationStatus::Sending)
        ->create(['created_at' => now()->subYears(2)]);
    $oldFailed = NotificationLog::factory()
        ->status(NotificationStatus::Failed)
        ->create(['created_at' => now()->subYears(2)]);
    $freshSent = NotificationLog::factory()
        ->status(NotificationStatus::Sent)
        ->create();

    $this->artisan('alerts:prune-logs', ['--days' => 365])
        ->expectsOutputToContain('Pruned 2 terminal deliveries')
        ->assertExitCode(0);

    expect(NotificationLog::query()->pluck('id'))
        ->not->toContain($oldSent->id)
        ->not->toContain($oldFailed->id)
        ->toContain($oldQueued->id)
        ->toContain($oldSending->id)
        ->toContain($freshSent->id);
});

it('writes no activity-log rows for delivery transitions', function () {
    $log = NotificationLog::factory()->create();

    $before = Activity::query()->count();

    $log->markSent('mail');
    $log->markDelivered();
    $log->markFailed('provider boom');

    expect(Activity::query()->count())->toBe($before);
});
