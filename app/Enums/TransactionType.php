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
    case Payment = 'payment';
    case Refund = 'refund';
    case Overpayment = 'overpayment';
    case Deposit = 'deposit';
    case DepositRefund = 'deposit_refund';
    case DepositDeduction = 'deposit_deduction';
    case OwnerInstallment = 'owner_installment';
    case Expense = 'expense';
    case ExpensePayment = 'expense_payment';
    case Maintenance = 'maintenance';
    case Insurance = 'insurance';
    case Fuel = 'fuel';
    case Depreciation = 'depreciation';
    case FineReceived = 'fine_received';
    case FinePayment = 'fine_payment';
    case FineRecovered = 'fine_recovered';
    case FineWriteOff = 'fine_write_off';
    case Payroll = 'payroll';
    case PayrollPayment = 'payroll_payment';
    case Commission = 'commission';
    case Advance = 'advance';
    case AdvanceRecovery = 'advance_recovery';
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
