<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Models\Customer;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;

/**
 * Customer driving licences approaching expiry.
 *
 * Reads customers.license_expiry_date, which is the canonical field the booking
 * flow validates against — not the customer_documents row, which is the scan of
 * the licence and may be absent even when the date is known.
 */
final class DrivingLicenceExpiringDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::DrivingLicenceExpiring;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = Customer::query()
            ->whereNotNull('license_expiry_date')
            ->whereDate('license_expiry_date', '<=', $now->addDays($rule->days_before)->toDateString());

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $customer) {
            $expiry = CarbonImmutable::parse((string) $customer->license_expiry_date);

            yield new AlertSubject(
                subject: $customer,
                branchId: $customer->branch_id,
                payload: [
                    'customer' => SubjectLabel::customer($customer),
                    'licence_number' => $customer->driving_license_number ?? '—',
                    'expiry_date' => $expiry->translatedFormat('d/m/Y'),
                    'days_remaining' => (int) $now->startOfDay()->diffInDays($expiry, false),
                ],
            );
        }
    }
}
