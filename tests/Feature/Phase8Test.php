<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\BookingStatus;
use App\Enums\CarDocumentType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Jobs\SendNotificationJob;
use App\Models\AlertRule;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarDocument;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\OwnerInstallment;
use App\Models\User;
use App\Services\Notification\Contracts\MessageDriver;
use App\Services\Notification\DeliveryResult;
use App\Services\Notification\DetectorRegistry;
use App\Services\Notification\Drivers\DatabaseDriver;
use App\Services\Notification\Drivers\MailDriver;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\MessagingService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\OutboundMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A driver that always fails, to exercise the retry path without a real provider.
 */
final class ExplodingDriver implements MessageDriver
{
    public static int $attempts = 0;

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Discord;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        self::$attempts++;

        throw MessageDeliveryException::unreachable('discord', 'connection timed out');
    }
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->notifications = app(NotificationService::class);

    $this->manager = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'manager@mcars.test',
    ]);
    $this->manager->assignRole(UserRole::Manager->value);

    // A booking due back in exactly 24 hours, used by the lead-time tests.
    $this->makeBooking = function (string $returnAt, string $status = BookingStatus::Active->value): Booking {
        $car = Car::factory()->create(['branch_id' => $this->branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        return Booking::create([
            'branch_id' => $this->branch->id,
            'pickup_branch_id' => $this->branch->id,
            'car_id' => $car->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'pickup_at' => CarbonImmutable::parse($returnAt)->subDays(3),
            'expected_return_at' => $returnAt,
            'daily_rate' => 5000.00,
            'days_count' => 3,
            'subtotal' => 15000.00,
            'total_amount' => 15000.00,
            'created_by_id' => $this->manager->id,
        ]);
    };
});

// ---------------------------------------------------------------------------
// Lead time
// ---------------------------------------------------------------------------

it('fires a rule at its lead time and not before', function () {
    $now = CarbonImmutable::parse('2026-08-02 09:00:00');

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    // Inside the one-day window.
    ($this->makeBooking)('2026-08-03 08:00:00');

    expect($this->notifications->evaluate($rule, $now))->toBe(1);

    NotificationLog::query()->delete();

    // Three days out — outside a one-day lead time.
    ($this->makeBooking)('2026-08-05 08:00:00');

    // The first booking is still inside the window, so exactly one fires again,
    // not two. The far-future one must stay silent.
    expect($this->notifications->evaluate($rule, $now))->toBe(1);

    expect(NotificationLog::query()->count())->toBe(1);
});

it('does not alert for a booking outside the window at all', function () {
    $now = CarbonImmutable::parse('2026-08-02 09:00:00');

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-20 08:00:00');

    expect($this->notifications->evaluate($rule, $now))->toBe(0);
    expect(NotificationLog::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Deduplication — ADR-012
// ---------------------------------------------------------------------------

it('produces the configured number of alerts over 30 daily runs, not 30', function () {
    // A document expiring in 30 days, a 7-day repeat and a ceiling of 5.
    $rule = AlertRule::factory()
        ->ofType(AlertType::CarDocumentExpiring)
        ->forRoles([UserRole::Manager])
        ->create([
            'days_before' => 30,
            'repeat_every_days' => 7,
            'max_repeats' => 5,
        ]);

    $car = Car::factory()->create(['branch_id' => $this->branch->id]);
    CarDocument::factory()->create([
        'car_id' => $car->id,
        'type' => CarDocumentType::Insurance->value,
        'expiry_date' => '2026-09-01',
        'reminder_days_before' => 0,
    ]);

    $start = CarbonImmutable::parse('2026-08-02 07:00:00');

    for ($day = 0; $day < 30; $day++) {
        $this->notifications->evaluate($rule, $start->addDays($day));
    }

    $sent = NotificationLog::query()->count();

    // 30 days at one alert per 7 days, capped at 5: days 0, 7, 14, 21, 28.
    expect($sent)->toBe(5)
        ->and($sent)->toBeLessThan(30);
});

it('never re-alerts when the rule has no repeat interval', function () {
    Queue::fake();

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1, 'repeat_every_days' => null]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $now = CarbonImmutable::parse('2026-08-02 09:00:00');

    foreach (range(0, 9) as $hour) {
        $this->notifications->evaluate($rule, $now->addHours($hour));
    }

    expect(NotificationLog::query()->count())->toBe(1);
});

it('counts a queued notification as already alerted', function () {
    // QUEUE_CONNECTION is sync under test, so without faking, dispatch would
    // deliver inline and the row would never be observed in its Queued state.
    Queue::fake();

    // The regression that matters: if dedup only looked at sent_at, an hourly
    // sweep would re-queue the same alert every hour while the first is still
    // waiting on the queue.
    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1, 'repeat_every_days' => 7]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $now = CarbonImmutable::parse('2026-08-02 09:00:00');
    $this->notifications->evaluate($rule, $now);

    expect(NotificationLog::query()->count())->toBe(1);
    expect(NotificationLog::query()->first()->status)->toBe(NotificationStatus::Queued);

    // Nothing has been delivered — sent_at is still null — yet the next sweep an
    // hour later must not queue a second copy.
    $this->notifications->evaluate($rule, $now->addHour());

    expect(NotificationLog::query()->count())->toBe(1);
});

it('allows a fresh alert after a failed delivery', function () {
    Queue::fake();

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1, 'repeat_every_days' => 7]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $now = CarbonImmutable::parse('2026-08-02 09:00:00');
    $this->notifications->evaluate($rule, $now);

    // A send that never landed should not hold the dedup window shut.
    NotificationLog::query()->first()->markFailed('provider down');

    $this->notifications->evaluate($rule, $now->addHour());

    expect(NotificationLog::query()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Recipient scoping
// ---------------------------------------------------------------------------

it('sends an owner-instalment alert to the office, never to an owner', function () {
    // Car owners are records, not accounts — there is no car_owner role to target.
    // The alert reaches accounting, and the owner's name rides in the payload so
    // whoever picks it up knows who to call.
    $accountant = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'accounting@mcars.test',
    ]);
    $accountant->assignRole(UserRole::Accountant->value);

    $owner = CarOwner::factory()->create(['branch_id' => $this->branch->id]);
    $car = Car::factory()->create(['branch_id' => $this->branch->id, 'car_owner_id' => $owner->id]);
    $agreement = CarOwnershipAgreement::factory()->create([
        'car_id' => $car->id,
        'car_owner_id' => $owner->id,
        'branch_id' => $this->branch->id,
    ]);

    OwnerInstallment::create([
        'car_ownership_agreement_id' => $agreement->id,
        'car_owner_id' => $owner->id,
        'car_id' => $car->id,
        'branch_id' => $this->branch->id,
        'sequence_number' => 1,
        'total_installments' => 12,
        'period_month' => '2026-08-01',
        'due_date' => '2026-08-04',
        'amount_due' => 40000.00,
        'status' => 'pending',
    ]);

    $rule = AlertRule::factory()
        ->ofType(AlertType::OwnerInstallmentDue)
        ->forRoles([UserRole::Accountant])
        ->create(['days_before' => 3]);

    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    $logs = NotificationLog::query()->get();

    expect($logs)->not->toBeEmpty()
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$accountant->id]);

    // Every recipient holds a staff role — nothing is addressed outside the office.
    $recipients = User::query()->whereIn('id', $logs->pluck('user_id'))->get();

    foreach ($recipients as $recipient) {
        expect($recipient->hasAnyRole(UserRole::values()))->toBeTrue();
    }

    // The owner is still identified in the payload.
    expect($logs->first()->payload['owner'] ?? null)->not->toBeNull();
});

it('scopes a branch rule to that branch only', function () {
    $other = Branch::factory()->create(['code' => 'ORAN', 'is_default' => false]);

    $otherManager = User::factory()->create(['branch_id' => $other->id, 'email' => 'oran@mcars.test']);
    $otherManager->assignRole(UserRole::Manager->value);

    // The rule belongs to the main branch; the booking does too.
    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1, 'branch_id' => $this->branch->id]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    $recipients = NotificationLog::query()->pluck('user_id')->all();

    expect($recipients)->toContain($this->manager->id)
        ->and($recipients)->not->toContain($otherManager->id);
});

it('ignores bookings from another branch under a branch-scoped rule', function () {
    $other = Branch::factory()->create(['code' => 'ORAN', 'is_default' => false]);

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1, 'branch_id' => $other->id]);

    // Booking is in the main branch, the rule watches Oran.
    ($this->makeBooking)('2026-08-03 08:00:00');

    expect($this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00')))->toBe(0);
});

// ---------------------------------------------------------------------------
// Delivery: queued, retried, never blocking
// ---------------------------------------------------------------------------

it('queues every send rather than delivering inline', function () {
    Queue::fake();

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    // A provider timeout must never stall a receptionist mid-checkout, so nothing
    // is delivered in the calling process.
    Queue::assertPushed(SendNotificationJob::class, 1);

    expect(NotificationLog::query()->first()->status)->toBe(NotificationStatus::Queued);
});

it('logs a failed send, records the error and marks the row failed', function () {
    // Hold the job back so delivery is driven explicitly below rather than
    // running inline on the sync queue during evaluate().
    Queue::fake();

    config()->set('notifications.drivers.discord', ExplodingDriver::class);
    ExplodingDriver::$attempts = 0;

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-03 08:00:00');
    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    $log = NotificationLog::query()->firstOrFail();

    // Running the job surfaces the provider failure as an exception so the queue
    // retries it, while the row already carries the reason.
    expect(fn () => app(MessagingService::class)->deliver($log->fresh()))
        ->toThrow(MessageDeliveryException::class);

    $log->refresh();

    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('connection timed out')
        ->and($log->attempts)->toBe(1)
        ->and(ExplodingDriver::$attempts)->toBe(1);
});

it('does not queue a channel whose driver is switched off', function () {
    // Discord has no webhook configured here, so it cannot deliver. Queueing it
    // anyway would write a row that can only be cancelled — and since a cancelled
    // row deliberately does not hold the dedup window shut, every hourly sweep
    // would queue another one forever.
    config()->set('notifications.discord.enabled', false);

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    $now = CarbonImmutable::parse('2026-08-02 09:00:00');

    expect($this->notifications->evaluate($rule, $now))->toBe(0);

    // The loop this guards against: a second sweep must not accumulate rows.
    $this->notifications->evaluate($rule, $now->addHour());

    expect(NotificationLog::query()->count())->toBe(0);
});

it('cancels rather than fails when a channel is disabled after queueing', function () {
    Queue::fake();

    // Enabled at queue time...
    config()->set('notifications.discord.enabled', true);
    config()->set('notifications.discord.webhook_url', 'https://discord.test/hook');

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-03 08:00:00');
    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    expect(NotificationLog::query()->count())->toBe(1);

    // ...switched off before the worker got to it. Cancel, do not fail: this is a
    // configuration state, not a delivery error, and must not burn retries.
    config()->set('notifications.discord.enabled', false);

    app(MessagingService::class)->deliver(NotificationLog::query()->firstOrFail());

    expect(NotificationLog::query()->first()->status)->toBe(NotificationStatus::Cancelled);
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

it('resolves a driver per channel from config', function () {
    $messaging = app(MessagingService::class);

    expect($messaging->driverFor(NotificationChannel::Database))
        ->toBeInstanceOf(DatabaseDriver::class)
        ->and($messaging->driverFor(NotificationChannel::Mail))
        ->toBeInstanceOf(MailDriver::class);

    // Swapping the provider is a config edit; calling code is untouched.
    config()->set('notifications.drivers.discord', ExplodingDriver::class);

    expect(app(MessagingService::class)->driverFor(NotificationChannel::Discord))
        ->toBeInstanceOf(ExplodingDriver::class);
});

it('prefers a branch rule over the global one', function () {
    AlertRule::factory()->ofType(AlertType::CashVariance)->create(['branch_id' => null, 'days_before' => 0]);
    $branchRule = AlertRule::factory()->ofType(AlertType::CashVariance)->create([
        'branch_id' => $this->branch->id,
        'days_before' => 2,
    ]);

    expect(AlertRule::resolveFor(AlertType::CashVariance, $this->branch->id)?->id)->toBe($branchRule->id);
});

it('skips inactive rules', function () {
    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingReturnDue)
        ->forRoles([UserRole::Manager])
        ->inactive()
        ->create(['days_before' => 1]);

    ($this->makeBooking)('2026-08-03 08:00:00');

    expect($this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00')))->toBe(0);
});

it('has a detector registered for every alert type', function () {
    $registry = app(DetectorRegistry::class);

    foreach (AlertType::cases() as $type) {
        expect($registry->for($type)->type())->toBe($type);
    }
});

it('posts a well-formed embed to the Discord webhook', function () {
    Queue::fake();
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    config()->set('notifications.discord.enabled', true);
    config()->set('notifications.discord.webhook_url', 'https://discord.test/hook');

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingOverdue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 0]);

    ($this->makeBooking)('2026-08-01 08:00:00');

    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    app(MessagingService::class)->deliver(NotificationLog::query()->firstOrFail());

    Http::assertSent(function ($request): bool {
        $embed = $request->data()['embeds'][0] ?? [];

        return $request->url() === 'https://discord.test/hook'
            && $request->method() === 'POST'
            && is_string($embed['title'] ?? null)
            && $embed['title'] !== ''
            // Overdue is a danger-coloured alert; Discord wants a decimal int.
            && ($embed['color'] ?? null) === 0xDC2626
            // Payload scalars become scannable fields.
            && count($embed['fields'] ?? []) > 0;
    });

    expect(NotificationLog::query()->first()->status)->toBe(NotificationStatus::Sent)
        ->and(NotificationLog::query()->first()->provider)->toBe('discord');
});

it('routes an alert type to its own webhook when one is configured', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('', 204)]);

    config()->set('notifications.discord.enabled', true);
    config()->set('notifications.discord.webhook_url', 'https://discord.test/default');
    config()->set('notifications.discord.webhooks', ['booking_overdue' => 'https://discord.test/ops']);

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingOverdue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 0]);

    ($this->makeBooking)('2026-08-01 08:00:00');
    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    app(MessagingService::class)->deliver(NotificationLog::query()->firstOrFail());

    Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.test/ops');
});

it('does not retry a permanent Discord rejection', function () {
    Queue::fake();
    Http::fake(['discord.test/*' => Http::response('bad payload', 400)]);

    config()->set('notifications.discord.enabled', true);
    config()->set('notifications.discord.webhook_url', 'https://discord.test/hook');

    $rule = AlertRule::factory()
        ->ofType(AlertType::BookingOverdue)
        ->onChannels([NotificationChannel::Discord])
        ->forRoles([UserRole::Manager])
        ->create(['days_before' => 0]);

    ($this->makeBooking)('2026-08-01 08:00:00');
    $this->notifications->evaluate($rule, CarbonImmutable::parse('2026-08-02 09:00:00'));

    // A 400 will be refused identically on every retry; retrying just burns queue.
    try {
        app(MessagingService::class)->deliver(NotificationLog::query()->firstOrFail());
        $this->fail('Expected a MessageDeliveryException.');
    } catch (MessageDeliveryException $e) {
        expect($e->retryable)->toBeFalse()
            ->and($e->statusCode)->toBe(400);
    }

    expect(NotificationLog::query()->first()->status)->toBe(NotificationStatus::Failed);
});
