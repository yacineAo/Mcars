<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default();

        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@mcars.local',
            'branch_id' => $branch?->id,
        ]);

        $admin->assignRole(UserRole::SuperAdmin->value);
    }
}
