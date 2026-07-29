<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // 1xxx — Assets
            ['code' => '1000', 'name' => 'Cash & Bank', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_postable' => false],
            ['code' => '1010', 'name' => 'Main Cash Box', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true, 'is_system' => true],
            ['code' => '1015', 'name' => 'Safe / Reserve', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true],
            ['code' => '1020', 'name' => 'Bank Account', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true],
            ['code' => '1030', 'name' => 'CCP Account', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true],
            ['code' => '1040', 'name' => 'BaridiMob Wallet', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true],
            ['code' => '1050', 'name' => 'Card / POS Clearing', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_cash_equivalent' => true],
            ['code' => '1100', 'name' => 'Receivables', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_postable' => false],
            ['code' => '1110', 'name' => 'Accounts Receivable – Customers', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1120', 'name' => 'Fines Receivable – Customers', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1130', 'name' => 'Employee Advances', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1140', 'name' => 'Other Receivables', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1200', 'name' => 'Fixed Assets', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_postable' => false],
            ['code' => '1210', 'name' => 'Vehicles – Company Owned', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1215', 'name' => 'Accumulated Depreciation – Vehicles', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Credit],
            ['code' => '1220', 'name' => 'Office Equipment', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1300', 'name' => 'Prepayments', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_postable' => false],
            ['code' => '1310', 'name' => 'Prepaid Insurance', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1320', 'name' => 'Prepaid Rent', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],

            // 2xxx — Liabilities
            ['code' => '2100', 'name' => 'Security Deposits Held', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit, 'is_system' => true],
            ['code' => '2200', 'name' => 'Accounts Payable – Car Owners', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2210', 'name' => 'Accounts Payable – Suppliers', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2220', 'name' => 'Fines Payable – Authorities', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2300', 'name' => 'Salaries Payable', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2310', 'name' => 'Social Contributions Payable', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2500', 'name' => 'Customer Credit Balances', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2600', 'name' => 'Inter-branch Clearing', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit, 'is_system' => true],

            // 3xxx — Equity
            ['code' => '3000', 'name' => 'Owner Capital', 'type' => AccountType::Equity, 'normal_balance' => NormalBalance::Credit],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => AccountType::Equity, 'normal_balance' => NormalBalance::Credit],
            ['code' => '3200', 'name' => 'Drawings', 'type' => AccountType::Equity, 'normal_balance' => NormalBalance::Debit],

            // 4xxx — Revenue
            ['code' => '4010', 'name' => 'Rental Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4020', 'name' => 'Additional Services Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4030', 'name' => 'Late Return Fees', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4040', 'name' => 'Excess Mileage Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4050', 'name' => 'Fuel Recharge Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4060', 'name' => 'Damage Recovery Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4070', 'name' => 'Fine Recharge Revenue', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4080', 'name' => 'Cleaning Fees', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4090', 'name' => 'Forfeited Deposits', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4900', 'name' => 'Cash Over / Misc Income', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],

            // 5xxx — Expenses
            ['code' => '5010', 'name' => 'Owner Car Rent', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5020', 'name' => 'Fuel', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5030', 'name' => 'Car Wash & Cleaning', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5040', 'name' => 'Maintenance & Repairs', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5050', 'name' => 'Insurance', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5060', 'name' => 'Taxes & Registration', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5070', 'name' => 'Office Rent', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5080', 'name' => 'Salaries & Wages', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5085', 'name' => 'Social Contributions', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5090', 'name' => 'Commissions', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5100', 'name' => 'Internet & Telecom', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5110', 'name' => 'Electricity & Water', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5120', 'name' => 'Marketing & Advertising', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5130', 'name' => 'Bank & Payment Charges', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5140', 'name' => 'Fines Absorbed by Company', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5150', 'name' => 'Depreciation – Vehicles', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5160', 'name' => 'Office Supplies', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5170', 'name' => 'Professional Fees', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '5900', 'name' => 'Cash Short / Other', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
        ];

        foreach ($accounts as $data) {
            $data['is_system'] ??= false;
            $data['is_cash_equivalent'] ??= false;
            $data['is_postable'] ??= true;
            $data['is_active'] = true;
            ChartOfAccount::create($data);
        }
    }
}
