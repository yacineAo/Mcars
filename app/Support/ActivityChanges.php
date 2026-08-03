<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the per-field old → new rows the activity-log view page renders.
 *
 * Redaction here is the second layer behind LogsActivity's
 * logExcept(getHidden()) (docs/resource/38-activity-log.md gap 1): rows
 * written before the trait excluded secrets are still in the table, so a
 * sensitive key may sit in the payload today — and when it does, it must not
 * reach the screen. The deny list is merged with the subject's own hidden
 * attributes, so a future secret column is covered the moment it is marked
 * hidden.
 */
final class ActivityChanges
{
    /** @var list<string> */
    public const REDACTED_KEYS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function rows(Activity $activity): array
    {
        $payload = $activity->attribute_changes;

        if (! $payload instanceof Collection) {
            return [];
        }

        $attributes = $payload->get('attributes');
        $old = $payload->get('old');

        if (! is_array($attributes)) {
            return [];
        }

        $subject = $activity->subject;
        $denied = array_unique([
            ...self::REDACTED_KEYS,
            ...($subject instanceof Model ? $subject->getHidden() : []),
        ]);

        $rows = [];

        foreach ($attributes as $field => $value) {
            if (in_array($field, $denied, true)) {
                continue;
            }

            $rows[] = [
                'field' => (string) $field,
                'old' => self::display(is_array($old) ? ($old[$field] ?? null) : null),
                'new' => self::display($value),
            ];
        }

        return $rows;
    }

    private static function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
    }
}
