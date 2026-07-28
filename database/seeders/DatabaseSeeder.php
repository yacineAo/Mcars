<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            CarCategorySeeder::class,
            VendorSeeder::class,
            ChartOfAccountSeeder::class,
            ExpenseCategorySeeder::class,
            FinancialAccountSeeder::class,
            // Phase 8. After RolePermissionSeeder: the rules name recipient roles.
            AlertRuleSeeder::class,
        ]);
    }
}
