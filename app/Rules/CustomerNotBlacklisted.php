<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Customer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * See docs/resource/09-customer.md: is_blacklisted blocks a new booking rather
 * than staying advisory-only. Refuses at the field level so a receptionist
 * sees why, rather than a booking silently going out to a customer the office
 * already flagged.
 *
 * `customer_id` on `BookingResource` is `disabled()` once a rental starts, but
 * a disabled field is still validated against its loaded value even though it
 * is not dehydrated (Filament's `isValidatedWhenNotDehydrated` defaults true) —
 * so without `$alreadyStarted`, editing *any* field on a booking whose customer
 * was blacklisted after pickup fails validation forever, on a field the user
 * never touched. `$alreadyStarted` is derived from the record loaded when the
 * form was built, not from submitted data, so it cannot be spoofed to skip the
 * check on a booking that has not actually started.
 */
final class CustomerNotBlacklisted implements ValidationRule
{
    public function __construct(
        private readonly bool $alreadyStarted = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->alreadyStarted) {
            return;
        }

        if (blank($value)) {
            return;
        }

        $customer = Customer::query()->find($value);

        if ($customer?->is_blacklisted) {
            $fail(__('This customer is blacklisted and cannot be booked.'));
        }
    }
}
