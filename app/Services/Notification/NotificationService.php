<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Jobs\SendNotificationJob;
use App\Models\AlertRule;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Evaluates alert rules and turns what is due into queued notifications.
 *
 * Orchestration only: detectors decide what is due, MessagingService decides how it
 * is delivered, and this class decides *whether* — which is where deduplication
 * lives.
 *
 * Nothing here talks to a provider. Each recipient/channel pair becomes a
 * notification_logs row plus a queued job, so a slow provider can never stall the
 * sweep, let alone a request.
 */
class NotificationService
{
    /**
     * Roles whose users are portal accounts tied to one customer or owner.
     *
     * A rule naming one of these must never fan out across every holder of the
     * role — it resolves only to the users the subject itself points at. This is
     * the guard behind "an owner alert never reaches another owner".
     */
    private const array SUBJECT_BOUND_ROLES = [
        UserRole::CarOwner->value,
        UserRole::Client->value,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DetectorRegistry $detectors,
        private readonly MessagingService $messaging,
    ) {}

    /**
     * Evaluate every active rule. Called hourly by the scheduler.
     *
     * @return int notifications queued
     */
    public function evaluateAll(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        $rules = AlertRule::query()->active()->with('branch')->get();

        foreach ($rules as $rule) {
            try {
                $queued += $this->evaluate($rule, $now);
            } catch (Throwable $e) {
                // One broken rule must not stop the other nine from firing.
                Log::error('Alert rule evaluation failed.', [
                    'alert_rule_id' => $rule->id,
                    'type' => $rule->type->value,
                    'exception' => $e,
                ]);
            }
        }

        return $queued;
    }

    /**
     * Evaluate one rule and queue whatever it turns up.
     *
     * @return int notifications queued
     */
    public function evaluate(AlertRule $rule, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        if (! $rule->is_active) {
            return 0;
        }

        $channels = $rule->channelEnums();

        if ($channels === []) {
            return 0;
        }

        $cap = (int) config('notifications.evaluation.max_subjects_per_rule', 500);
        $queued = 0;
        $examined = 0;

        foreach ($this->detectors->for($rule->type)->detect($rule, $now) as $subject) {
            if ($examined >= $cap) {
                // Never truncate silently — a capped sweep looks identical to a
                // quiet one in the logs otherwise.
                Log::warning('Alert rule hit the per-sweep subject cap; remaining subjects deferred.', [
                    'alert_rule_id' => $rule->id,
                    'type' => $rule->type->value,
                    'cap' => $cap,
                ]);

                break;
            }

            $examined++;
            $queued += $this->raise($rule, $subject, $now, $channels);
        }

        return $queued;
    }

    /**
     * Queue an alert for one subject, once per (recipient, channel), subject to
     * deduplication.
     *
     * Public so an event-driven alert — a backup failure, say — can raise itself
     * without a detector.
     *
     * @param list<NotificationChannel>|null $channels
     * @return int notifications queued
     */
    public function raise(
        AlertRule $rule,
        AlertSubject $subject,
        ?CarbonImmutable $now = null,
        ?array $channels = null,
    ): int {
        $now ??= CarbonImmutable::now();
        $channels ??= $rule->channelEnums();

        // Drop channels whose driver is switched off. Queueing them anyway would
        // write a row that can only ever be cancelled — and because a cancelled
        // row deliberately does not hold the dedup window shut, every sweep would
        // queue another one, forever.
        $channels = array_values(array_filter(
            $channels,
            fn (NotificationChannel $channel): bool => $this->messaging->driverFor($channel)->isEnabled(),
        ));

        if ($channels === []) {
            return 0;
        }

        $recipients = $this->resolveRecipients($rule, $subject);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $queued = 0;

        foreach ($channels as $channel) {
            if ($this->isSuppressed($rule, $subject, $channel, $now)) {
                continue;
            }

            foreach ($recipients as $user) {
                $address = $this->addressFor($user, $channel);

                if ($address === null) {
                    continue;
                }

                if ($this->prefersDigest($user, $channel)) {
                    continue;
                }

                $log = $this->createLog($rule, $subject, $channel, $user, $address);
                $queued++;

                // Dispatch after commit: the worker is fast enough to pick the job
                // up before the surrounding transaction commits otherwise, and then
                // it cannot find the row it was handed.
                $this->db->connection()->afterCommit(
                    static fn () => SendNotificationJob::dispatch($log->id),
                );
            }
        }

        return $queued;
    }

    /**
     * ADR-012 deduplication.
     *
     * Suppresses when either the repeat window is still open or the repeat ceiling
     * has been reached. Keyed on (template_key, related_type, related_id, channel),
     * which is exactly the covering index on notification_logs.
     *
     * Counts Queued and Sending rows as well as Sent — an alert already on the
     * queue is an alert the recipient is about to get, and ignoring it would let
     * every hourly sweep re-queue the same thing.
     */
    private function isSuppressed(
        AlertRule $rule,
        AlertSubject $subject,
        NotificationChannel $channel,
        CarbonImmutable $now,
    ): bool {
        $base = NotificationLog::query()
            ->where('template_key', $rule->template_key)
            ->where('related_type', $subject->morphType())
            ->where('related_id', $subject->morphId())
            ->where('channel', $channel->value)
            ->occupyingDedupWindow();

        // No repeat configured: alert once per subject, ever.
        if ($rule->repeat_every_days === null) {
            return (clone $base)->exists();
        }

        $alreadySent = (clone $base)->count();

        if (! $rule->allowsAnotherRepeat($alreadySent)) {
            return true;
        }

        return (clone $base)
            ->where('created_at', '>=', $now->subDays($rule->repeat_every_days))
            ->exists();
    }

    /**
     * Who should hear about this.
     *
     * Staff roles resolve to all active users holding the role in the relevant
     * branch. Portal roles resolve only to users the subject points at.
     *
     * @return Collection<int, User>
     */
    private function resolveRecipients(AlertRule $rule, AlertSubject $subject): Collection
    {
        $roles = $rule->recipientRoleEnums();

        if ($roles === []) {
            return collect();
        }

        $staffRoles = [];
        $portalRoles = [];

        foreach ($roles as $role) {
            in_array($role->value, self::SUBJECT_BOUND_ROLES, strict: true)
                ? $portalRoles[] = $role->value
                : $staffRoles[] = $role->value;
        }

        $recipients = collect();

        if ($staffRoles !== []) {
            $query = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $staffRoles));

            // A branch-scoped rule reaches that branch's staff only. Users with no
            // branch (head office) are included, since they oversee all of them.
            $branchId = $rule->branch_id ?? $subject->branchId;

            if ($branchId !== null) {
                $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
            }

            $recipients = $recipients->merge($query->get());
        }

        if ($portalRoles !== [] && $subject->targetedUserIds !== []) {
            $recipients = $recipients->merge(
                User::query()
                    ->where('is_active', true)
                    ->whereIn('id', $subject->targetedUserIds)
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', $portalRoles))
                    ->get(),
            );
        }

        return $recipients->unique('id')->values();
    }

    /**
     * The address this channel needs, or null when the user cannot receive on it.
     *
     * A user without an email is skipped rather than failed — that is a gap in
     * their profile, not a delivery error worth retrying.
     */
    private function addressFor(User $user, NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Database => (string) $user->getKey(),
            NotificationChannel::Mail => $user->email !== '' ? $user->email : null,
            // Discord posts to a shared webhook rather than to an individual, so
            // the address is the channel itself.
            NotificationChannel::Discord => 'webhook',
        };
    }

    /**
     * Digest users trade immediate email for one daily summary. The in-app bell and
     * Discord are unaffected — a digest is about inbox volume, not about hiding.
     */
    private function prefersDigest(User $user, NotificationChannel $channel): bool
    {
        return $channel === NotificationChannel::Mail
            && (bool) ($user->notification_digest ?? false);
    }

    private function createLog(
        AlertRule $rule,
        AlertSubject $subject,
        NotificationChannel $channel,
        User $user,
        string $address,
    ): NotificationLog {
        return NotificationLog::create([
            'branch_id' => $subject->branchId ?? $rule->branch_id,
            'alert_rule_id' => $rule->id,
            'channel' => $channel,
            'template_key' => $rule->template_key,
            'user_id' => $user->getKey(),
            'recipient' => $address,
            'locale' => $user->locale ?? config('notifications.default_locale', 'fr'),
            'related_type' => $subject->morphType(),
            'related_id' => $subject->morphId(),
            'payload' => $subject->payload,
            'status' => NotificationStatus::Queued,
            'queued_at' => now(),
        ]);
    }

    /**
     * Raise an alert for a subject outside the polling cycle.
     *
     * For events that happen once rather than states that persist — the Phase 10
     * backup job is the intended first caller.
     */
    public function alertOnce(AlertType $type, AlertSubject $subject, ?int $branchId = null): int
    {
        $rule = AlertRule::resolveFor($type, $branchId ?? $subject->branchId);

        return $rule === null ? 0 : $this->raise($rule, $subject);
    }
}
