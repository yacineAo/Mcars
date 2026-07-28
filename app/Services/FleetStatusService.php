<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CarStatus;
use App\Models\Car;
use InvalidArgumentException;

class FleetStatusService
{
    /** @var array<string, list<string>> status => allowed next statuses */
    private const TRANSITIONS = [
        'available' => ['reserved', 'maintenance', 'out_of_service', 'sold', 'returned_to_owner'],
        'reserved' => ['available', 'rented', 'cancelled'],
        'rented' => ['available', 'maintenance', 'out_of_service'],
        'maintenance' => ['available', 'out_of_service', 'sold'],
        'out_of_service' => ['available', 'maintenance', 'sold', 'returned_to_owner'],
        'sold' => [],
        'returned_to_owner' => [],
    ];

    public function transition(Car $car, CarStatus $newStatus): void
    {
        $current = $car->status->value;

        if ($current === $newStatus->value) {
            return;
        }

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($newStatus->value, $allowed, strict: true)) {
            throw new InvalidArgumentException(
                "Cannot transition car [{$car->id}] from {$current} to {$newStatus->value}. "
                .'Allowed: '.implode(', ', $allowed),
            );
        }

        if ($newStatus->isTerminal() && $car->agreements()->where('status', 'active')->exists()) {
            throw new InvalidArgumentException(
                "Cannot set car [{$car->id}] to {$newStatus->value} while active ownership agreements exist.",
            );
        }

        $car->status = $newStatus;
        $car->save();
    }

    /** @return list<string> */
    public function allowedTransitions(Car $car): array
    {
        return self::TRANSITIONS[$car->status->value] ?? [];
    }
}
