<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        return [
            'branch_id' => null,
            'alert_rule_id' => null,
            'channel' => NotificationChannel::Database,
            'template_key' => 'alerts.booking_return_due',
            'user_id' => null,
            'recipient' => fake()->safeEmail(),
            'locale' => 'fr',
            'related_type' => null,
            'related_id' => null,
            'payload' => [],
            'status' => NotificationStatus::Queued,
            'attempts' => 0,
            'cost' => '0.00',
            'queued_at' => now(),
        ];
    }

    public function about(Model $subject): self
    {
        return $this->state(fn (): array => [
            'related_type' => $subject->getMorphClass(),
            'related_id' => $subject->getKey(),
        ]);
    }

    public function status(NotificationStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'sent_at' => $status === NotificationStatus::Sent ? now() : null,
        ]);
    }
}
