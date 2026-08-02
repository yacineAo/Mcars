<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\AlertType;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\Detectors\BackupFailedDetector;
use App\Services\Notification\Detectors\BookingOverdueDetector;
use App\Services\Notification\Detectors\BookingReturnDueDetector;
use App\Services\Notification\Detectors\CarDocumentExpiringDetector;
use App\Services\Notification\Detectors\CashVarianceDetector;
use App\Services\Notification\Detectors\CustomerPaymentOverdueDetector;
use App\Services\Notification\Detectors\DrivingLicenceExpiringDetector;
use App\Services\Notification\Detectors\MaintenanceDueDetector;
use App\Services\Notification\Detectors\OwnerInstallmentDueDetector;
use App\Services\Notification\Detectors\RecurringExpenseDueDetector;
use App\Services\Notification\Detectors\ScheduledReportFailedDetector;
use Illuminate\Contracts\Container\Container;

/**
 * Maps an alert type to the detector that finds its subjects.
 *
 * The match is exhaustive over AlertType, so adding a case to the enum breaks this
 * at analysis time rather than silently producing an alert type that never fires —
 * which is exactly how the previous implementation went quiet.
 */
final class DetectorRegistry
{
    /** @var array<string, AlertDetector> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function for(AlertType $type): AlertDetector
    {
        if (isset($this->resolved[$type->value])) {
            return $this->resolved[$type->value];
        }

        $class = match ($type) {
            AlertType::BookingReturnDue => BookingReturnDueDetector::class,
            AlertType::BookingOverdue => BookingOverdueDetector::class,
            AlertType::CustomerPaymentOverdue => CustomerPaymentOverdueDetector::class,
            AlertType::OwnerInstallmentDue => OwnerInstallmentDueDetector::class,
            AlertType::CarDocumentExpiring => CarDocumentExpiringDetector::class,
            AlertType::DrivingLicenceExpiring => DrivingLicenceExpiringDetector::class,
            AlertType::MaintenanceDue => MaintenanceDueDetector::class,
            AlertType::RecurringExpenseDue => RecurringExpenseDueDetector::class,
            AlertType::CashVariance => CashVarianceDetector::class,
            AlertType::BackupFailed => BackupFailedDetector::class,
            AlertType::ScheduledReportFailed => ScheduledReportFailedDetector::class,
        };

        return $this->resolved[$type->value] = $this->container->make($class);
    }
}
