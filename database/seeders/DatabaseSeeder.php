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
            // Phase 1: RoleSeeder, PermissionSeeder, AdminUserSeeder
            // Phase 2: CarCategorySeeder, VendorSeeder
            // Phase 4: ChartOfAccountSeeder, ExpenseCategorySeeder, FinancialAccountSeeder
        ]);
    }
}
