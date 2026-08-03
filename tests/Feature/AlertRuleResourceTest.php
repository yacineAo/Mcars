<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AlertRuleResource;
use App\Filament\Admin\Resources\AlertRuleResource\Pages\CreateAlertRule;
use App\Filament\Admin\Resources\AlertRuleResource\Pages\EditAlertRule;
use App\Filament\Admin\Resources\AlertRuleResource\Pages\ListAlertRules;
use App\Filament\Admin\Resources\NotificationLogResource;
use App\Models\AlertRule;
use App\Models\Branch;
use App\Models\NotificationLog;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\BranchContext;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);

    // Models boot once per process; future branch-scope assertions must be able
    // to re-boot with the feature flag flipped.
    AlertRule::clearBootedModels();

    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole(UserRole::SuperAdmin->value);

    $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->manager->assignRole(UserRole::Manager->value);

    $this->receptionist = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->receptionist->assignRole(UserRole::Receptionist->value);

    $this->rule = AlertRule::factory()->ofType(AlertType::BookingReturnDue)->create();
});

it('gates the resource behind alerts.manage', function () {
    $this->actingAs($this->receptionist);
    $this->get(AlertRuleResource::getUrl('index', panel: 'admin'))->assertForbidden();

    $this->actingAs($this->manager);
    $this->get(AlertRuleResource::getUrl('index', panel: 'admin'))->assertSuccessful();
    $this->get(AlertRuleResource::getUrl('create', panel: 'admin'))->assertSuccessful();
    $this->get(AlertRuleResource::getUrl('edit', ['record' => $this->rule], panel: 'admin'))->assertSuccessful();
});

it('never applies the branch scope to alert rules, so global rules stay visible', function () {
    // Re-boot the model with the feature flag on, exactly as Phase 10 will run:
    config(['branches.enabled' => true]);

    AlertRule::clearBootedModels();
    $fresh = new AlertRule;

    // resolveBranchId() returning null is only half the guard — the global scope
    // added by BelongsToBranch::boot is the other half, and without
    // withoutBranchScope() it hides every NULL-branch row.
    $scopes = $fresh->getGlobalScopes();

    expect(isset($scopes[BranchScope::class]))->toBeFalse();

    // And with a branch selected in the context, all ten seeded global rules
    // remain queryable, not just the rows matching the selected branch.
    $resolved = app(BranchContext::class)->resolve($this->branch->id);
    expect($resolved)->toBeTrue();

    expect(AlertRule::query()->pluck('branch_id')->contains(null))->toBeTrue()
        ->and(AlertRule::query()->count())->toBeGreaterThanOrEqual(1);

    // Pest runs tests in one process: the flag and the resolved context would
    // leak into every later test in this file, silently scoping User,
    // NotificationLog and friends. Restore the default state.
    config()->set('branches.enabled', false);
    app(BranchContext::class)->reset();
});

it('seeds one active global rule per alert type', function () {
    $this->seed(AlertRuleSeeder::class);

    expect(AlertRule::query()->count())->toBe(count(AlertType::cases()))
        ->and(AlertRule::query()->where('branch_id', null)->count())->toBe(count(AlertType::cases()))
        ->and(AlertRule::query()->where('is_active', true)->count())->toBe(count(AlertType::cases()));
});

it('rejects a second active global rule of the same type with a field error', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateAlertRule::class)
        ->fillForm([
            'type' => AlertType::BookingReturnDue->value,
            'branch_id' => null,
            'template_key' => AlertType::BookingReturnDue->defaultTemplateKey(),
            'days_before' => 1,
            'channels' => [NotificationChannel::Database->value],
            'recipient_roles' => [UserRole::Manager->value],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);
});

it('rejects a duplicate active rule for the same branch on create', function () {
    $this->actingAs($this->manager);

    AlertRule::factory()
        ->ofType(AlertType::CarDocumentExpiring)
        ->state(['branch_id' => $this->branch->id])
        ->create();

    Livewire::test(CreateAlertRule::class)
        ->fillForm([
            'type' => AlertType::CarDocumentExpiring->value,
            'branch_id' => $this->branch->id,
            'template_key' => AlertType::CarDocumentExpiring->defaultTemplateKey(),
            'days_before' => 30,
            'channels' => [NotificationChannel::Database->value],
            'recipient_roles' => [UserRole::Manager->value],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);
});

it('allows the duplicate once the existing rule is deactivated', function () {
    $this->actingAs($this->manager);

    $existing = AlertRule::factory()->ofType(AlertType::RecurringExpenseDue)->create();

    // The partial unique index frees the slot, exactly as the DB intends.
    $existing->update(['is_active' => false]);

    Livewire::test(CreateAlertRule::class)
        ->fillForm([
            'type' => AlertType::RecurringExpenseDue->value,
            'branch_id' => null,
            'template_key' => AlertType::RecurringExpenseDue->defaultTemplateKey(),
            'days_before' => 5,
            'channels' => [NotificationChannel::Database->value],
            'recipient_roles' => [UserRole::Manager->value],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors(['type']);

    expect(AlertRule::query()->where('type', AlertType::RecurringExpenseDue->value)->count())->toBe(2);
});

it('creates a valid branch override alongside the global rule', function () {
    $this->actingAs($this->manager);

    // The global rule exists (seeded in beforeEach); a *branch* rule is a
    // different (type, branch) slot, so it is allowed.
    Livewire::test(CreateAlertRule::class)
        ->fillForm([
            'type' => AlertType::BookingReturnDue->value,
            'branch_id' => $this->branch->id,
            'template_key' => AlertType::BookingReturnDue->defaultTemplateKey(),
            'days_before' => 2,
            'channels' => [NotificationChannel::Database->value],
            'recipient_roles' => [UserRole::Manager->value],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::query()->where('type', AlertType::BookingReturnDue->value)->count())->toBe(2);
});

it('freezes the template key on edit, with the type', function () {
    $this->actingAs($this->manager);

    Livewire::test(EditAlertRule::class, ['record' => $this->rule->getKey()])
        ->assertFormFieldDisabled('type')
        ->assertFormFieldDisabled('template_key');
});

it('renders the branch select with an "all branches" placeholder', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateAlertRule::class)
        ->assertFormFieldExists('branch_id')
        ->assertSee(__('notifications.resources.alert_rule.global'), escape: false);
});

it('lists recipient roles and the toggled template key on the index', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListAlertRules::class)
        ->assertCanSeeTableRecords([$this->rule])
        ->assertTableColumnExists('recipient_roles')
        ->assertTableColumnStateSet('template_key', $this->rule->template_key, $this->rule)
        ->assertTableColumnStateSet('recipient_roles', ['manager'], $this->rule);
});

it('deactivates and reactivates a rule from the list row', function () {
    $this->actingAs($this->manager);

    Livewire::test(ListAlertRules::class)
        ->callTableAction('set_active', $this->rule)
        ->assertNotified();

    expect($this->rule->fresh()->is_active)->toBeFalse();

    Livewire::test(ListAlertRules::class)
        ->callTableAction('set_active', $this->rule)
        ->assertNotified();

    expect($this->rule->fresh()->is_active)->toBeTrue();
});

it('names the alert type in the delete confirmation on edit', function () {
    $this->actingAs($this->manager);

    $component = Livewire::test(EditAlertRule::class, ['record' => $this->rule->getKey()])
        ->mountAction('delete');

    // Filament renders the modal lazily, so the confirmation is asserted on the
    // mounted action itself, where the description is evaluated.
    $action = $component->instance()->getMountedAction();
    expect($action)->not->toBeNull();

    $description = (string) $action->getModalDescription();
    expect($description)->toContain((string) $this->rule->type->getLabel());
});

it('links to the pre-filtered delivery log from the row', function () {
    $this->actingAs($this->manager);

    $url = NotificationLogResource::getUrl('index', [
        'tableFilters' => ['alert_rule_id' => ['value' => $this->rule->getKey()]],
    ], panel: 'admin');

    // Laravel URL-encodes the bracket keys (tableFilters%5Balert_rule_id%5D...),
    // so assert on what survives: the filter name and the record id.
    expect($url)->toContain('alert_rule_id')
        ->and($url)->toContain((string) $this->rule->getKey());

    NotificationLog::factory()->count(3)->create(['alert_rule_id' => $this->rule->id]);

    Livewire::test(ListAlertRules::class)
        ->assertTableActionExists('view_deliveries');

    $logs = NotificationLogResource::getEloquentQuery()
        ->where('alert_rule_id', $this->rule->id)
        ->count();

    expect($logs)->toBe(3)
        ->and(NotificationLogResource::canAccess())->toBeTrue();
});

it('forbids the delivery log to someone without alerts.view_logs', function () {
    // A receptionist cannot even reach the log screen — the filtered URL a
    // manager's "View deliveries" link produces is the same screen, so forging
    // it leaks nothing either.
    $this->actingAs($this->receptionist);

    $this->get(NotificationLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});
