<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The alert catalogue of REQ-17.
 *
 * Each case owns its default template key and its default lead time. Those are
 * only defaults for the seeder — the live lead time lives on `alert_rules`, so a
 * manager changes it without a deploy.
 */
enum AlertType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case BookingReturnDue = 'booking_return_due';
    case BookingOverdue = 'booking_overdue';
    case CustomerPaymentOverdue = 'customer_payment_overdue';
    case OwnerInstallmentDue = 'owner_installment_due';
    case CarDocumentExpiring = 'car_document_expiring';
    case DrivingLicenceExpiring = 'driving_licence_expiring';
    case MaintenanceDue = 'maintenance_due';
    case RecurringExpenseDue = 'recurring_expense_due';
    case CashVariance = 'cash_variance';
    case BackupFailed = 'backup_failed';

    public function getColor(): string
    {
        return match ($this) {
            self::BookingOverdue,
            self::CustomerPaymentOverdue,
            self::CashVariance,
            self::BackupFailed => 'danger',

            self::CarDocumentExpiring,
            self::DrivingLicenceExpiring,
            self::MaintenanceDue,
            self::OwnerInstallmentDue => 'warning',

            self::BookingReturnDue,
            self::RecurringExpenseDue => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::BookingReturnDue => 'heroicon-o-arrow-uturn-left',
            self::BookingOverdue => 'heroicon-o-exclamation-triangle',
            self::CustomerPaymentOverdue => 'heroicon-o-banknotes',
            self::OwnerInstallmentDue => 'heroicon-o-calendar-days',
            self::CarDocumentExpiring => 'heroicon-o-document-text',
            self::DrivingLicenceExpiring => 'heroicon-o-identification',
            self::MaintenanceDue => 'heroicon-o-wrench-screwdriver',
            self::RecurringExpenseDue => 'heroicon-o-arrow-path',
            self::CashVariance => 'heroicon-o-scale',
            self::BackupFailed => 'heroicon-o-server-stack',
        };
    }

    /**
     * Translation key under `notifications.*` for the message body.
     *
     * Stored on the rule rather than derived at send time, so renaming a template
     * never rewrites history — an old log keeps pointing at what it actually sent.
     */
    public function defaultTemplateKey(): string
    {
        return 'alerts.'.$this->value;
    }

    /** Lead time in days the seeder starts the rule at. */
    public function defaultDaysBefore(): int
    {
        return match ($this) {
            self::BookingReturnDue => 1,
            self::OwnerInstallmentDue => 3,
            self::MaintenanceDue => 7,
            self::RecurringExpenseDue => 5,
            self::CarDocumentExpiring,
            self::DrivingLicenceExpiring => 30,

            // Reactive alerts: the condition is already true when detected.
            self::BookingOverdue,
            self::CustomerPaymentOverdue,
            self::CashVariance,
            self::BackupFailed => 0,
        };
    }

    /**
     * How often the same subject may be re-alerted, in days.
     *
     * This is the ADR-012 dial. A document expiring in 30 days must produce a
     * handful of alerts, not thirty.
     */
    public function defaultRepeatEveryDays(): int
    {
        return match ($this) {
            self::BookingOverdue,
            self::CustomerPaymentOverdue => 3,
            self::BookingReturnDue => 1,
            self::CashVariance,
            self::BackupFailed => 1,
            default => 7,
        };
    }

    /** Hard ceiling on repeats per subject, so a stale record cannot alert forever. */
    public function defaultMaxRepeats(): int
    {
        return match ($this) {
            self::BookingReturnDue => 1,
            self::CashVariance, self::BackupFailed => 3,
            default => 5,
        };
    }

    /** @return list<UserRole> */
    public function defaultRecipientRoles(): array
    {
        return match ($this) {
            self::BookingReturnDue,
            self::BookingOverdue => [UserRole::Manager, UserRole::Receptionist],

            self::CustomerPaymentOverdue,
            self::RecurringExpenseDue,
            self::CashVariance => [UserRole::Manager, UserRole::Accountant],

            // The owner is not a system user — the office is told, and tells them.
            self::OwnerInstallmentDue => [UserRole::Manager, UserRole::Accountant],

            self::CarDocumentExpiring,
            self::MaintenanceDue => [UserRole::Manager, UserRole::MaintenanceOfficer],

            self::DrivingLicenceExpiring => [UserRole::Manager, UserRole::Receptionist],

            self::BackupFailed => [UserRole::SuperAdmin],
        };
    }
}
