<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

/**
 * One global rule per alert type, using the defaults declared on AlertType.
 *
 * Seeded rather than hardcoded so the system alerts sensibly out of the box while
 * every dial stays editable in AlertRuleResource — REQ-17 asks for lead times owned
 * by the manager, not by a deploy.
 *
 * Idempotent: re-running never duplicates a rule, and never overwrites a lead time
 * someone has since tuned.
 */
class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AlertType::cases() as $type) {
            AlertRule::query()->firstOrCreate(
                [
                    'type' => $type->value,
                    'branch_id' => null,
                ],
                [
                    'template_key' => $type->defaultTemplateKey(),
                    'days_before' => $type->defaultDaysBefore(),
                    'repeat_every_days' => $type->defaultRepeatEveryDays(),
                    'max_repeats' => $type->defaultMaxRepeats(),
                    'channels' => $this->channelsFor($type),
                    'recipient_roles' => array_map(
                        static fn ($role): string => $role->value,
                        $type->defaultRecipientRoles(),
                    ),
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * In-app for everything; email and Discord only for what genuinely warrants
     * interrupting someone. Starting quiet and letting a manager widen a channel
     * is safer than starting loud — the first week of a noisy system is when people
     * learn to ignore it.
     *
     * @return list<string>
     */
    private function channelsFor(AlertType $type): array
    {
        $channels = [NotificationChannel::Database->value];

        $escalated = match ($type) {
            AlertType::BookingOverdue,
            AlertType::CustomerPaymentOverdue,
            AlertType::CarDocumentExpiring,
            AlertType::CashVariance,
            AlertType::BackupFailed => true,
            default => false,
        };

        if ($escalated) {
            $channels[] = NotificationChannel::Mail->value;
            $channels[] = NotificationChannel::Discord->value;
        }

        return $channels;
    }
}
