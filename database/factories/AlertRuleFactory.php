<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    public function definition(): array
    {
        $type = fake()->randomElement(AlertType::cases());

        return [
            'branch_id' => null,
            'type' => $type,
            'template_key' => $type->defaultTemplateKey(),
            'days_before' => $type->defaultDaysBefore(),
            'repeat_every_days' => $type->defaultRepeatEveryDays(),
            'max_repeats' => $type->defaultMaxRepeats(),
            'channels' => [NotificationChannel::Database->value],
            'recipient_roles' => [UserRole::Manager->value],
            'is_active' => true,
        ];
    }

    public function ofType(AlertType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'template_key' => $type->defaultTemplateKey(),
            'days_before' => $type->defaultDaysBefore(),
        ]);
    }

    /** @param list<NotificationChannel> $channels */
    public function onChannels(array $channels): self
    {
        return $this->state(fn (): array => [
            'channels' => array_map(static fn (NotificationChannel $c): string => $c->value, $channels),
        ]);
    }

    /** @param list<UserRole> $roles */
    public function forRoles(array $roles): self
    {
        return $this->state(fn (): array => [
            'recipient_roles' => array_map(static fn (UserRole $r): string => $r->value, $roles),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
