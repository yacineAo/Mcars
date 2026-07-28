<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FinancialAccountType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;

class FinancialAccountSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default();
        if ($branch === null) {
            return;
        }

        $cashAccount = ChartOfAccount::where('code', '1010')->first();
        $bankAccount = ChartOfAccount::where('code', '1020')->first();
        $ccpAccount = ChartOfAccount::where('code', '1030')->first();

        if ($cashAccount !== null) {
            FinancialAccount::create([
                'branch_id' => $branch->id,
                'ledger_account_id' => $cashAccount->id,
                'name' => 'Main Cash Box',
                'type' => FinancialAccountType::CashBox,
                'opened_on' => now(),
                'is_default_for_cash' => true,
                'is_active' => true,
            ]);
        }

        if ($bankAccount !== null) {
            FinancialAccount::create([
                'branch_id' => $branch->id,
                'ledger_account_id' => $bankAccount->id,
                'name' => 'Main Bank Account',
                'type' => FinancialAccountType::Bank,
                'opened_on' => now(),
                'is_active' => true,
            ]);
        }

        if ($ccpAccount !== null) {
            FinancialAccount::create([
                'branch_id' => $branch->id,
                'ledger_account_id' => $ccpAccount->id,
                'name' => 'CCP Account',
                'type' => FinancialAccountType::Ccp,
                'opened_on' => now(),
                'is_active' => true,
            ]);
        }
    }
}
