<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case RentalRevenue = 'rental_revenue';
    case ExtrasRevenue = 'extras_revenue';
    case LateFee = 'late_fee';
    case ExcessMileage = 'excess_mileage';
    case FuelRecharge = 'fuel_recharge';
    case DamageRecovery = 'damage_recovery';
    case CleaningFee = 'cleaning_fee';
    case FineRecharge = 'fine_recharge';
    case DepositForfeited = 'deposit_forfeited';
    case CustomerPayment = 'customer_payment';
    case CustomerRefund = 'customer_refund';
    case Overpayment = 'overpayment';
    case DepositHeld = 'deposit_held';
    case DepositRefunded = 'deposit_refunded';
    case DepositDeducted = 'deposit_deducted';
    case OwnerRentAccrued = 'owner_rent_accrued';
    case OwnerPayment = 'owner_payment';
    case Expense = 'expense';
    case ExpensePayment = 'expense_payment';
    case Maintenance = 'maintenance';
    case Insurance = 'insurance';
    case Fuel = 'fuel';
    case Depreciation = 'depreciation';
    case FineReceived = 'fine_received';
    case FinePaid = 'fine_paid';
    case FineRecovered = 'fine_recovered';
    case FineWrittenOff = 'fine_written_off';
    case SalaryAccrued = 'salary_accrued';
    case SalaryPaid = 'salary_paid';
    case Commission = 'commission';
    case EmployeeAdvance = 'employee_advance';
    case AdvanceRecovered = 'advance_recovered';
    case CashTransfer = 'cash_transfer';
    case CashDepositToBank = 'cash_deposit_to_bank';
    case OpeningFloat = 'opening_float';
    case CashOver = 'cash_over';
    case CashShort = 'cash_short';
    case Capital = 'capital';
    case Drawings = 'drawings';
    case Tax = 'tax';
    case BankCharge = 'bank_charge';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    public function getColor(): string
    {
        return match ($this) {
            self::Reversal => 'danger',
            self::CustomerPayment, self::DepositHeld, self::Capital => 'success',
            self::Expense, self::Maintenance, self::CashShort => 'danger',
            self::RentalRevenue, self::ExtrasRevenue => 'success',
            default => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Reversal => 'heroicon-o-arrow-uturn-left',
            self::CustomerPayment => 'heroicon-o-credit-card',
            self::Expense, self::Maintenance => 'heroicon-o-shopping-cart',
            self::RentalRevenue => 'heroicon-o-truck',
            self::CashOver => 'heroicon-o-exclamation-triangle',
            self::CashShort => 'heroicon-o-exclamation-circle',
            default => 'heroicon-o-document-text',
        };
    }
}
