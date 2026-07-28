<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Car;
use App\Models\CarOwner;
use App\Models\Customer;

/**
 * Human labels for alert subjects.
 *
 * Lives here rather than as accessors on Car/Customer/CarOwner so Phase 8 does not
 * reach back into Phase 2 and 3 models. If these labels turn out to be wanted in
 * the UI too, promoting them to model accessors is the right move then.
 */
final class SubjectLabel
{
    private const string MISSING = '—';

    public static function customer(?Customer $customer): string
    {
        if ($customer === null) {
            return self::MISSING;
        }

        // A company booking is identified by its trading name; a person by theirs.
        return self::firstNonEmpty([
            $customer->company_name,
            trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
            $customer->code,
        ]);
    }

    public static function carOwner(?CarOwner $owner): string
    {
        if ($owner === null) {
            return self::MISSING;
        }

        return self::firstNonEmpty([
            $owner->company_name,
            trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')),
        ]);
    }

    public static function car(?Car $car): string
    {
        if ($car === null) {
            return self::MISSING;
        }

        $description = trim(($car->brand ?? '').' '.($car->model ?? ''));
        $plate = $car->registration_number;

        if ($description !== '' && $plate !== '') {
            return "{$description} ({$plate})";
        }

        return self::firstNonEmpty([$description, $plate]);
    }

    /** @param list<string|null> $candidates */
    private static function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return self::MISSING;
    }
}
