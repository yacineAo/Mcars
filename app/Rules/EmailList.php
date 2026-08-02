<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One address, or several separated by commas, or blank.
 *
 * The schedule recipient field is a comma-separated list because a monthly P&L
 * usually goes to more than one person and a second, linked table of recipients
 * would be furniture for a screen that fits in one field.
 */
final class EmailList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('reports.resources.report_definition.help.email_invalid'));

            return;
        }

        foreach (explode(',', $value) as $address) {
            if (filter_var(trim($address), FILTER_VALIDATE_EMAIL) === false) {
                $fail(__('reports.resources.report_definition.help.email_invalid'));

                return;
            }
        }
    }
}
