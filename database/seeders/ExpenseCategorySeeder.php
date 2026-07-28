<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Owner Car Rent', 'slug' => 'owner-car-rent', 'ledger_code' => '5010', 'is_car_related' => true],
            ['name' => 'Fuel', 'slug' => 'fuel', 'ledger_code' => '5020', 'is_car_related' => true],
            ['name' => 'Car Wash & Cleaning', 'slug' => 'car-wash', 'ledger_code' => '5030', 'is_car_related' => true],
            ['name' => 'Maintenance & Repairs', 'slug' => 'maintenance', 'ledger_code' => '5040', 'is_car_related' => true],
            ['name' => 'Insurance', 'slug' => 'insurance', 'ledger_code' => '5050', 'is_car_related' => true],
            ['name' => 'Taxes & Registration', 'slug' => 'taxes-registration', 'ledger_code' => '5060', 'is_car_related' => true],
            ['name' => 'Office Rent', 'slug' => 'office-rent', 'ledger_code' => '5070', 'is_car_related' => false],
            ['name' => 'Salaries & Wages', 'slug' => 'salaries-wages', 'ledger_code' => '5080', 'is_car_related' => false],
            ['name' => 'Social Contributions', 'slug' => 'social-contributions', 'ledger_code' => '5085', 'is_car_related' => false],
            ['name' => 'Commissions', 'slug' => 'commissions', 'ledger_code' => '5090', 'is_car_related' => false],
            ['name' => 'Internet & Telecom', 'slug' => 'internet-telecom', 'ledger_code' => '5100', 'is_car_related' => false],
            ['name' => 'Electricity & Water', 'slug' => 'electricity-water', 'ledger_code' => '5110', 'is_car_related' => false],
            ['name' => 'Marketing & Advertising', 'slug' => 'marketing-advertising', 'ledger_code' => '5120', 'is_car_related' => false],
            ['name' => 'Bank & Payment Charges', 'slug' => 'bank-charges', 'ledger_code' => '5130', 'is_car_related' => false],
            ['name' => 'Fines Absorbed by Company', 'slug' => 'fines-absorbed', 'ledger_code' => '5140', 'is_car_related' => true],
            ['name' => 'Depreciation – Vehicles', 'slug' => 'depreciation', 'ledger_code' => '5150', 'is_car_related' => true],
            ['name' => 'Office Supplies', 'slug' => 'office-supplies', 'ledger_code' => '5160', 'is_car_related' => false],
            ['name' => 'Professional Fees', 'slug' => 'professional-fees', 'ledger_code' => '5170', 'is_car_related' => false],
            ['name' => 'Cash Short / Other', 'slug' => 'cash-short-other', 'ledger_code' => '5900', 'is_car_related' => false],
        ];

        foreach ($categories as $data) {
            $account = ChartOfAccount::where('code', $data['ledger_code'])->first();
            if ($account === null) {
                continue;
            }
            ExpenseCategory::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'ledger_account_id' => $account->id,
                'is_car_related' => $data['is_car_related'],
                'is_active' => true,
            ]);
        }
    }
}
