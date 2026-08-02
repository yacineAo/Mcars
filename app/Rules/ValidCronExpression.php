<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A five-field cron expression, or blank.
 *
 * The cron is optional on the form — a definition can be saved without a
 * schedule — so null and empty string pass; anything else must be an expression
 * `Cron\CronExpression` itself can evaluate, or the definition silently never
 * fires and the operator only finds out by noticing the missing report.
 */
final class ValidCronExpression implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
            $fail(__('reports.resources.report_definition.help.cron_invalid'));
        }
    }
}
