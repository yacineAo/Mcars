<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Models\Customer;

class CustomerService
{
    public function toggleBlacklist(Customer $customer, ?string $reason = null): Customer
    {
        $isBlacklisted = ! $customer->is_blacklisted;

        $customer->update([
            'is_blacklisted' => $isBlacklisted,
            'blacklist_reason' => $isBlacklisted ? $reason : null,
            'blacklisted_at' => $isBlacklisted ? now() : null,
        ]);

        return $customer->fresh();
    }
}
